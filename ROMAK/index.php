<?php
// --- CONFIG AT THE VERY TOP OF THE FILE ---
 $CONFIG = [
    'HOOKS_BASE_URL' => 'http://192.168.1.86:7000', // Adjust if Flask is on a different port/domain
    'HOOKS' => [
        'submit' => 'run',
        'clear'  => 'clear',
        'save'   => 'save',
        'poll'   => 'poll',
        'lib'    => 'lib',
        'delete' => 'delete',
        'pipe'   => 'pipe'
    ],
    'POLL_INTERVAL_MS' => 2000 // Poll every 2 seconds
];
// -------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR-Terminal</title>
    <style>
        /* Global Reset & Box-Sizing to prevent truncation */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        body, html {
            margin: 0;
            padding: 0;
            height: 100vh;
            width: 100vw;
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            overflow: hidden;
        }

        /* Main Container: 75% Left | 25% Right */
        .main-container {
            display: flex;
            height: 100%;
            width: 100%;
        }

        .left-panel {
            width: 75%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #444;
        }

        .right-column {
            width: 25%;
            background: #252526;
            padding-left: 20px;
            padding-top: 15px;
            overflow-y: auto; /* Allow scrolling if list is long */
        }

        /* Left Panel Sections */
        .input-section {
            height: 40%;
            display: flex;
            flex-direction: column;
            padding-left: 20px;
            padding-top: 10px;
            padding-right: 10px;
        }

        .control-section {
            height: 10%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            border-top: 1px solid #444;
            border-bottom: 1px solid #444;
            background: #2d2d2d;
        }

        .bottom-section {
            height: 50%;
            display: flex;
            flex-direction: column;
            padding-left: 20px;
            padding-top: 10px;
            padding-right: 10px;
        }

        /* Form Elements */
        textarea {
            width: 100%;
            flex-grow: 1;
            background: #1e1e1e;
            color: #d4d4d4;
            border: 1px solid #444;
            padding: 8px;
            resize: none;
            font-family: monospace;
            outline: none;
        }

        textarea:focus {
            border-color: #0e639c;
        }

        button {
            padding: 10px 24px;
            cursor: pointer;
            background: #0e639c;
            color: white;
            border: none;
            font-weight: bold;
            font-family: monospace;
        }

        button:hover { background: #1177bb; }
        button:disabled { background: #555; cursor: not-allowed; }

        #clear-btn { background: #c5c5c5; color: black; }
        #clear-btn:hover { background: #e0e0e0; }

        #save-btn { background: #388a3e; }
        #save-btn:hover { background: #45a049; }

        .status-indicator {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }

        h3 { margin: 0 0 5px 0; font-size: 14px; color: #ccc; }

        /* Right Column List Styles */
        #lib-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }

        #lib-list li {
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between; /* Puts link on left, [X] on right */
            align-items: center;
            padding-right: 5px;
        }

        #lib-list a {
            color: #4ec9b0;
            text-decoration: none;
            font-size: 13px;
            word-break: break-all;
            margin-right: 10px; /* Space between name and X */
        }

        #lib-list a:hover {
            text-decoration: underline;
            color: #6fd5be;
        }

        .delete-btn {
            color: #ff3333;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0; /* Prevents X from squishing */
        }

        .delete-btn:hover {
            color: #ff6666;
        }
    </style>
</head>
<body>

    <!-- Inject PHP Config into JavaScript -->
    <script>
        const CONFIG = <?php echo json_encode($CONFIG); ?>;
    </script>

    <div class="main-container">

        <!-- LEFT PANEL (75%) -->
        <div class="left-panel">

            <!-- 1. Input Form (40%) -->
            <div class="input-section">
                <h3>Input</h3>
                <textarea id="input-area" rows="7" placeholder="Enter text here..."></textarea>
            </div>

            <!-- 2. Control Area (10%) -->
            <div class="control-section">
                <!-- Removed onclick attributes for cross-browser safety -->
                <button id="submit-btn">Submit</button>
                <button id="pipe-btn">Pipe</button>
                <button id="clear-btn">Clear</button>
                <button id="save-btn">Save</button>
                <span id="status-text" class="status-indicator">Ready</span>
            </div>

            <!-- 3. Bottom Panel (50%) -->
            <div class="bottom-section">
                <h3>Output</h3>
                <textarea id="output-area" readonly placeholder="Results will appear here..."></textarea>
            </div>

        </div>

        <!-- RIGHT COLUMN (25%) -->
        <div class="right-column">
            <h3>Library</h3>
            <ul id="lib-list">
                <li>Loading...</li>
            </ul>
        </div>

    </div>

    <!-- LOGIC SCRIPT -->
    <script>
        const inputArea = document.getElementById('input-area');
        const outputArea = document.getElementById('output-area');
        const statusText = document.getElementById('status-text');
        const submitBtn = document.getElementById('submit-btn');
        const clearBtn = document.getElementById('clear-btn');
        const saveBtn = document.getElementById('save-btn');
        const libList = document.getElementById('lib-list');
        const pipeBtn = document.getElementById('pipe-btn');

        let pollInterval = null;

        // --- ATTACH EVENT LISTENERS (Safer than inline onclick) ---
        submitBtn.addEventListener('click', handleSubmit);
        pipeBtn.addEventListener('click', handlePipe);
        clearBtn.addEventListener('click', handleClear);
        saveBtn.addEventListener('click', handleSave);

        // --- LIBRARY RENDERING ---
        async function loadLib() {
            const url = `${CONFIG.HOOKS_BASE_URL}/${CONFIG.HOOKS.lib}`;
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'content='
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const text = await response.text();
                const files = text.split(/\s+/).filter(f => f.trim() !== '');

                libList.innerHTML = ''; // Clear loading text

                if (files.length === 0) {
                    libList.innerHTML = '<li>No files found</li>';
                    return;
                }

                // Create links and delete buttons
                files.forEach(file => {
                    const li = document.createElement('li');

                    // 1. The Link
                    const a = document.createElement('a');
                    a.href = `v.php?f=${file}`; // URL still has .txt
                    a.target = '_blank';

                    // Strip .txt for display only
                    let displayName = file;
                    if (displayName.toLowerCase().endsWith('.txt')) {
                        displayName = displayName.slice(0, -4);
                    }
                    a.textContent = displayName;

                    // 2. The Delete [X]
                    const deleteBtn = document.createElement('span');
                    deleteBtn.textContent = '[X]';
                    deleteBtn.className = 'delete-btn';
                    deleteBtn.title = `Delete ${file}`;
                    deleteBtn.onclick = () => handleDelete(file);

                    li.appendChild(a);
                    li.appendChild(deleteBtn);
                    libList.appendChild(li);
                });

            } catch (error) {
                console.error("Lib fetch error:", error);
                libList.innerHTML = `<li style="color: red;">Error loading lib</li>`;
            }
        }

        // --- DELETE HANDLER ---
        async function handleDelete(filename) {
            if (!confirm(`Are you sure you want to delete ${filename}?`)) return;
            const result = await postToHook(CONFIG.HOOKS.delete, filename);
            loadLib();
        }

        // Load the library list when the page loads
        document.addEventListener('DOMContentLoaded', loadLib);


        // --- PROCESS HANDLING ---
        function stopPolling(message) {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            statusText.innerText = message;
            submitBtn.disabled = false;
            clearBtn.disabled = false;
            saveBtn.disabled = false;
            pipeBtn.disabled = false;
        }

        async function postToHook(hookName, content) {
            const url = `${CONFIG.HOOKS_BASE_URL}/${hookName}`;
            console.log("Posting to:", url, "Content:", content); // DEBUG
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `content=${encodeURIComponent(content)}`
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return await response.text();
            } catch (error) {
                console.error("Fetch error:", error);
                return `Error: ${error.message}`;
            }
        }

        async function pollStatus() {
            const url = `${CONFIG.HOOKS_BASE_URL}/${CONFIG.HOOKS.poll}`;
            try {
                const response = await fetch(url, { method: 'GET' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const text = await response.text();
                const shortText = text.length > 40 ? text.substring(0, 40) + '...' : text;
                statusText.innerText = `Polling: ${shortText}`;

                if (text.trim() === "ALL DONE") {
                    stopPolling("Process Complete");
                }
            } catch (error) {
                console.error("Polling error:", error);
                stopPolling("POLLING ERROR - STOPPED");
                outputArea.value += "\n\n[POLLING ERROR] Connection failed or blocked by CORS.";
            }
        }

        async function handleSubmit() {
            try {
                const content = inputArea.value;
                outputArea.value = "Submitting to run hook...";
                statusText.innerText = "Running...";

                submitBtn.disabled = true;
                pipeBtn.disabled = true;
                clearBtn.disabled = true;
                saveBtn.disabled = true;

                const result = await postToHook(CONFIG.HOOKS.submit, content);
                outputArea.value = result;

                if (!pollInterval) {
                    pollInterval = setInterval(pollStatus, CONFIG.POLL_INTERVAL_MS);
                }
            } catch (err) {
                console.error("Submit Error:", err);
                stopPolling("Submit Failed");
            }
        }

        async function handleClear() {
            try {
                statusText.innerText = "Clearing...";
                const content = inputArea.value;
                const result = await postToHook(CONFIG.HOOKS.clear, content);

                outputArea.value = '';
                inputArea.value = '';
                statusText.innerText = "Cleared";
            } catch (err) {
                console.error("Clear Error:", err);
                stopPolling("Clear Failed");
            }
        }

        async function handleSave() {
            try {
                statusText.innerText = "Saving...";
                const content = inputArea.value;
                const result = await postToHook(CONFIG.HOOKS.save, content);

                outputArea.value = result;
                statusText.innerText = "Saved";
            } catch (err) {
                console.error("Save Error:", err);
                stopPolling("Save Failed");
            }
        }

        async function handlePipe() {
            try {
                console.log("handlePipe triggered!"); // DEBUG
                const content = inputArea.value;
                outputArea.value = "Submitting to pipe hook...";
                statusText.innerText = "Piping...";

                submitBtn.disabled = true;
                pipeBtn.disabled = true;
                clearBtn.disabled = true;
                saveBtn.disabled = true;

                // Send to the 'pipe' hook
                const result = await postToHook(CONFIG.HOOKS.pipe, content);
                console.log("Pipe result:", result); // DEBUG
                outputArea.value = result;

                // Start polling the 'poll' hook
                if (!pollInterval) {
                    pollInterval = setInterval(pollStatus, CONFIG.POLL_INTERVAL_MS);
                }
            } catch (err) {
                console.error("Pipe Error:", err);
                stopPolling("Pipe Failed"); // Prevents hanging if something crashes
            }
        }

    </script>
</body>
</html>
