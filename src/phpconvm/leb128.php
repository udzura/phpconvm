<?php

trait Leb128Helpers {
    public function fetchUleb128($buf) {
        $dest = 0;
        $level = 0;
        
        while (($b = fread($buf, 1)) !== '') {
            $c = ord($b);
            
            $upper = ($c >> 7);
            $lower = ($c & ((1 << 7) - 1));
            $dest |= $lower << (7 * $level);
            
            if ($upper == 0) {
                return $dest;
            }
            
            if ($level > 6) {
                break;
            }
            $level++;
        }
        
        throw new LoadError("unreachable! debug: dest = $dest level = $level");
    }

    public function fetchSleb128($buf) {
        $dest = 0;
        $level = 0;
        
        while (($b = fread($buf, 1)) !== '') {
            $c = ord($b);
            
            $upper = ($c >> 7);
            $lower = ($c & ((1 << 7) - 1));
            $dest |= $lower << (7 * $level);
            
            if ($upper == 0) {
                break;
            }
            
            if ($level > 6) {
                throw new LoadError("unreachable! debug: dest = $dest level = $level");
            }
            $level++;
        }
        
        $shift = 7 * ($level + 1) - 1;
        return $dest | -($dest & (1 << $shift));
    }
}