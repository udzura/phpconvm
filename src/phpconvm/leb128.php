<?php

trait Leb128Helpers {
    public function fetchUleb128($buf) {
        $dest = 0;
        $level = 0;
        
        while (($b = fread($buf, 1)) !== false && $b !== '') {
            $c = ord($b);
            
            $upper = ($c >> 7);
            $lower = ($c & ((1 << 7) - 1));
            $dest |= $lower << (7 * $level);
            
            if ($upper == 0) {
                return $dest;
            }
            
            if ($level > 6) {
                throw new LoadError("LEB128 too long: dest = $dest level = $level");
            }
            $level++;
        }

        throw new LoadError("fetchUleb128: buffer too short during LEB128 decode: dest = $dest level = $level");
    }

    public function fetchSleb128($buf) {
        $dest = 0;
        $level = 0;

        while (($b = fread($buf, 1)) !== false && $b !== '') {
            $c = ord($b);
            
            $upper = ($c >> 7);
            $lower = ($c & ((1 << 7) - 1));
            $dest |= $lower << (7 * $level);
            
            if ($upper == 0) {
                break;
            }
            
            if ($level > 6) {
                throw new LoadError("fetchSleb128: LEB128 too long: dest = $dest level = $level");
            }
            $level++;
        }
        
        // Ruby版の符号拡張ロジックを正確に実装
        $shift = 7 * ($level + 1) - 1;
        $result = $dest | -($dest & (1 << $shift));
        return $result;
    }
}