<?php

require_once "vm.php";

$vm = new VM();

// WASM example using PhpConVm
try {
    // WAsmファイルを読み込んでテスト
    $vm->loadWasm('examples/add.wasm');
    $result = $vm->add(100, 200);
    echo "Result: $result\n"; // 期待値: 300
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}