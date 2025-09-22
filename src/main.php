<?php

require_once "vm.php";

$vm = new VM();
$vm->hoge = "Hello, World!\n";
echo $vm->hoge;

// WASM example using PhpConVm
try {
    // WAsmファイルを読み込んでテスト
    // $vm->loadWasm('examples/add.wasm');
    // $result = $vm->add(100, 200);
    // echo "Result: $result\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}