#!/usr/bin/perl 

my $summary = 
  `./lll gpt120 "Consolidate the final version. At the end print VERSIONS CONSOLIDATED: N <<1>>" SPOOL/output.out 2>&1`;

print "SUMMARY:\n$summary\n";

open my $fh, '>', 'SPOOL/output.out' or die "Cannot open SPOOL/output.out: $!";
print $fh $summary;
close $fh;

