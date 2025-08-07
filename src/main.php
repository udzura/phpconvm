<?php
include_once "vm.php";

$vm = new VM();
$vm->hoge = "Hello, World!\n";
echo $vm->hoge;