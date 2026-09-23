#!/usr/bin/perl 

my $head15 = `head -15 LAST/output.out > head15.txt`;

my $filename = `./lll mini "Print the 15 chars filename Use only alphanumeric and no spaces to reflect the summary of this <<1>> DO NOT USE THE WORD SUMMARY or 15 or CHARS" head15.txt 2>&1`; chomp $filename;

$filename .= ".txt";

system "cp LAST/output.out LIB/$filename";

print "$filename - Saved!\n";


