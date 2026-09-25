#!/usr/bin/perl
use strict;
use warnings;

my $BASE = "/u2/aixkit/ROMAK";
chdir $BASE or die "Can't chdir to $BASE: $!";

my $restart = shift || 0;
my $CMD = "/usr/bin/python3 $BASE/app.py";
my $PORT = 7000;

my $port = `ss -ltnp | grep ':$PORT\\b'`;
chomp $port;

my ($pid) = $port =~ /pid=(\d+)/;

if ($pid) {
    my $cmdline = `ps -p $pid -o args=`;
    chomp $cmdline;

    if ($cmdline eq $CMD) {
        if (!$restart) {
            print "RUNNING OK PID $pid\n";
            exit;
        }

        print "RESTARTING PID $pid\n";
        kill 'TERM', $pid;
        sleep 1;
    } else {
        print "PORT $PORT OCCUPIED BY PID $pid\n";
        system("ps -fp $pid");
        exit 1;
    }
}

print "STARTING\n";
system("nohup $CMD >/dev/null 2>&1 &");
