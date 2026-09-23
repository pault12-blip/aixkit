
<?php
$d=__DIR__.'/LIB';
$f=basename($_GET['f']??'');
if(!$f||$f==='.'||$f==='..')exit('400');
if(pathinfo($f,PATHINFO_EXTENSION)!=='txt')exit('403');
$p=realpath("$d/$f");
if(!$p||strpos($p,realpath($d).'/')!==0||!is_file($p))exit('404');
$c=htmlspecialchars(file_get_contents($p),ENT_QUOTES);
$c=preg_replace_callback('#(https?://\S+|www\.\S+)#i',fn($m)=>'<a href="'.(str_starts_with($m[1],'www')?'http://':'').$m[1].'" target="_blank">'.$m[1].'</a>',$c);
header('Content-Type:text/html');
echo"<html><head><meta charset=utf-8><style>body{font:14px monospace;white-space:pre-wrap;padding:20px}a{color:#06c}</style></head><body>$c</body></html>";

