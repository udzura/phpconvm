<?php

require_once "vm.php";

// コマンドライン引数の解析
$path = $argv[1] ?? null;
$method = $argv[2] ?? null;
$args = array_slice($argv, 3);

if (!$path) {
    echo "Usage: php main.php <wasm_file> [method_name] [args...]\n";
    echo "Examples:\n";
    echo "  php main.php examples/add.wasm add 100 200\n";
    echo "  php main.php examples/helloworld.wasm _start\n";
    echo "  php main.php examples/fib.wasm fib 10\n";
    exit(1);
}

if (!file_exists($path)) {
    echo "Error: File '$path' not found\n";
    exit(1);
}

try {
    $vm = new VM();
    $vm->loadWasm($path);
    
    // メソッド名が指定されていない場合は_startを試す
    if (!$method && $vm->instance->runtime->callable('_start')) {
        $vm->instance->runtime->call('_start', []);
    } elseif ($method) {
        // 引数を適切な型に変換
        $converted_args = array_map(function($arg) {
            if (strpos($arg, '.') !== false) {
                return floatval($arg);
            } else {
                return intval($arg);
            }
        }, $args);
        
        $result = $vm->instance->runtime->call($method, $converted_args);
        
        if ($result !== null) {
            echo "Return value: " . var_export($result, true) . "\n";
        }
    } else {
        echo "Error: No method specified and no '_start' function found\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}