<?php
function dump($var)
{
    return highlight_string("<?php\n\$array = " . var_export($var, true) . ";", true);
}
