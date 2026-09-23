#!/usr/bin/perl
use strict;
use warnings;

my $BASE = "/u2/aixkit/ROMAK";
chdir $BASE or die "Can't chdir to $BASE: $!";

my $kill = shift || 0;
my $CMDS = "/usr/bin/python3 app.py";
my $PORT = 7000;

# Check if the port is being listened on
my $port_listening = `ss -ltnp | grep ':$PORT'`;

chomp $port_listening;
if ( !$port_listening ) {
    $kill = 1;    # force kill & restart if port not listening
}

my @commands = grep { $_ !~ /^\s*$/ } split /\n/, $CMDS;
foreach my $cmd (@commands) {
    my $escaped_cmd = quotemeta($cmd);
    next if $cmd =~ m/#/;
    my $is_running = `pgrep -f '^$escaped_cmd\$'`;

    if ($is_running) {
        if ($kill) {
            print "killing $cmd\n";
            system("pkill -f '^$escaped_cmd\$'");
            print "starting $cmd\n";
            system("nohup $cmd &");
        } else {
            print "$cmd - running\n";
        }
    } else {
        unless ($kill) {
            print "starting $cmd\n";
            system("nohup $cmd &");
        }
    }
}
