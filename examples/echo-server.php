<?php
/**
 * 示例：简单的 echo 服务器
 * 读取一行输入，输出处理后的结果
 */

while (true) {
    $input = fgets(STDIN);
    if ($input === false) {
        break;
    }
    
    $input = trim($input);
    
    if ($input === 'quit' || $input === 'exit') {
        echo "Bye!\n";
        break;
    }
    
    if ($input === '') {
        continue;
    }
    
    echo "Received: {$input}\n";
    echo "Length: " . strlen($input) . "\n";
    echo "---\n";
}
