from flask import Flask, request
from flask_cors import CORS
import os
import subprocess
import threading
import glob
import shutil
from datetime import datetime

app = Flask(__name__)
CORS(app)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SPOOL_DIR = os.path.join(BASE_DIR, 'SPOOL')
LAST_DIR = os.path.join(BASE_DIR, 'LAST')
TRACE_PATH = os.path.join(BASE_DIR, 'trace.log')
_trace_lock = threading.Lock()


def trace(what, bytes_in=0, bytes_out=0):
    now = datetime.now()
    line = f"{now:%Y-%m-%d} {now:%H:%M:%S} {what} {bytes_in} {bytes_out}\n"
    with _trace_lock:
        with open(TRACE_PATH, 'a') as fh:
            fh.write(line)


def ensure_spool_dir():
    os.makedirs(SPOOL_DIR, exist_ok=True)


def read_workers():
    cfg_path = os.path.join(BASE_DIR, 'workers.cfg')
    workers = []
    with open(cfg_path, 'r') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#'):
                workers.append(line)
    return workers


def rotate_spool():
    """Archive SPOOL → LAST, create fresh SPOOL."""
    if os.path.exists(LAST_DIR):
        shutil.rmtree(LAST_DIR)
        trace('rotate.last_removed', 0, 0)
    if os.path.exists(SPOOL_DIR):
        shutil.move(SPOOL_DIR, LAST_DIR)
        trace('rotate.spool_moved', 0, 0)
    os.makedirs(SPOOL_DIR, exist_ok=True)
    trace('rotate.spool_fresh', 0, 0)


def do_run(content):
    trace('do_run.enter', len(content.encode('utf-8')), 0)

    ensure_spool_dir()

    input_path = os.path.join(SPOOL_DIR, 'input.in')
    with open(input_path, 'w') as fh:
        fh.write(content)
    trace('do_run.input_written', len(content.encode('utf-8')), 0)

    workers = read_workers()
    trace('do_run.workers', len(workers), 0)

    thread_info = []

    for word in workers:
        log_path = os.path.join(SPOOL_DIR, f'{word}.log')
        err_path = os.path.join(SPOOL_DIR, f'{word}.err')
        exc_holder = [None]

        def _run_worker(_word=word, _input=input_path,
                        _log=log_path, _err=err_path, _exc=exc_holder):
            trace(f'do_run.worker.start:{_word}', 0, 0)
            try:
                with open(_log, 'w') as log_f, open(_err, 'w') as err_f:
                    proc = subprocess.Popen(
                        ['./lll', _word, _input],
                        stdout=log_f, stderr=err_f,
                        cwd=BASE_DIR,
                    )
                    proc.wait()
                trace(f'do_run.worker.done:{_word}', 0, proc.returncode)
            except Exception as e:
                _exc[0] = e
                trace(f'do_run.worker.error:{_word}:{e}', 0, 0)

        t = threading.Thread(target=_run_worker)
        t.start()
        thread_info.append((t, exc_holder, word))

    for t, _, _ in thread_info:
        t.join()

    trace('do_run.all_workers_done', len(thread_info), 0)

    output_parts = []
    all_errors = []

    for _, exc_holder, word in thread_info:
        if exc_holder[0] is not None:
            all_errors.append(f"[{word}] thread error: {exc_holder[0]}")
            continue

        log_path = os.path.join(SPOOL_DIR, f'{word}.log')
        err_path = os.path.join(SPOOL_DIR, f'{word}.err')

        if os.path.exists(log_path):
            with open(log_path, 'r') as fh:
                log_content = fh.read()
            output_parts.append(f"[{word}]\n")
            output_parts.append(log_content)
            trace(f'do_run.log_read:{word}', 0, len(log_content.encode('utf-8')))

        if os.path.exists(err_path):
            with open(err_path, 'r') as fh:
                err_content = fh.read()
            if err_content.strip():
                all_errors.append(f"[{word}]\n{err_content}")
                trace(f'do_run.err_nonempty:{word}', 0, len(err_content.encode('utf-8')))
            else:
                try:
                    os.remove(err_path)
                except FileNotFoundError:
                    pass

    output = ''.join(output_parts)
    if all_errors:
        output += '-----\n' + '\n'.join(all_errors)

    trace('do_run.output_size', 0, len(output.encode('utf-8')))

    output_path = os.path.join(SPOOL_DIR, 'output.out')
    with open(output_path, 'w') as fh:
        fh.write(output)
    trace('do_run.output_written', 0, len(output.encode('utf-8')))

    trace('do_run.exit', len(content.encode('utf-8')), len(output.encode('utf-8')))
    return output, output_path


@app.route('/run', methods=['POST'])
def run():
    content = request.form.get('content', '')
    output, _ = do_run(content)
    rotate_spool()
    trace('run', len(content.encode('utf-8')), len(output.encode('utf-8')))
    return output


@app.route('/pipe', methods=['POST'])
def pipe():
    content = request.form.get('content', '')
    output, output_path = do_run(content)

    try:
        result = subprocess.run(
            ['./pipe.pl', output_path],
            capture_output=True, text=True,
            cwd=BASE_DIR,
        )
        piped = result.stdout
        if result.stderr.strip():
            piped += '-----\n' + result.stderr
        trace('pipe', len(content.encode('utf-8')), len(piped.encode('utf-8')))
    except Exception as e:
        piped = f"pipe error: {e}"
        trace(f'pipe.error:{e}', len(content.encode('utf-8')), 0)

    rotate_spool()
    return piped


@app.route('/poll', methods=['GET'])
def poll():
    try:
        ps = subprocess.run(['ps', 'ax'], capture_output=True, text=True)
        lines = ps.stdout.splitlines()
        matching = [l for l in lines if 'lll' in l and 'grep' not in l]
        if not matching:
            out = "ALL DONE"
        else:
            out = '\n'.join(matching)
        trace('poll', 0, len(out.encode('utf-8')))
        return out
    except Exception as e:
        trace(f'poll.error:{e}', 0, 0)
        return f"poll error: {e}"


@app.route('/save', methods=['POST'])
def save():
    try:
        result = subprocess.run(
            ['./save.pl'],
            capture_output=True, text=True,
            cwd=BASE_DIR,
        )
        output = result.stdout
        if result.stderr.strip():
            output += '-----\n' + result.stderr
        trace('save', 0, len(output.encode('utf-8')))
        return output
    except Exception as e:
        trace(f'save.error:{e}', 0, 0)
        return f"save error: {e}"


@app.route('/clear', methods=['POST'])
def clear():
    trace('clear', 0, 0)
    return ''


@app.route('/lib', methods=['POST'])
def lib():
    lib_dir = os.path.join(BASE_DIR, 'LIB')
    try:
        files = sorted(os.listdir(lib_dir))
        out = '\n'.join(files)
        trace('lib', 0, len(out.encode('utf-8')))
        return out
    except Exception as e:
        trace(f'lib.error:{e}', 0, 0)
        return f"lib error: {e}"


@app.route('/delete', methods=['POST'])
def delete():
    filename = request.form.get('content', '').strip()
    bin_ = len(filename.encode('utf-8'))
    if not filename:
        out = 'error: no filename provided'
        trace('delete', bin_, len(out.encode('utf-8')))
        return out
    filepath = os.path.join(BASE_DIR, 'LIB', filename)
    try:
        os.remove(filepath)
        trace('delete', bin_, 0)
        return ''
    except FileNotFoundError:
        trace(f'delete.error.notfound:{filename}', bin_, 0)
        return f'error: {filename} not found'
    except Exception as e:
        trace(f'delete.error:{e}', bin_, 0)
        return f'delete error: {e}'


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=7000)

