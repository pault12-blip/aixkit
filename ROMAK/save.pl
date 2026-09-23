#!/usr/bin/perl 

my $filename = `./lll nano "Print the 15 chars filename Use only alphanumeric and no spaces to reflect the summary of this <<1>> DO NOT USE THE WORD SUMMARY or 15 or CHARS" SPOOL/output.out 2>&1`; chomp $filename;

$filename .= ".txt";

system "cp SPOOL/output.out LIB/$filename";

print "$filename - Saved!\n";


