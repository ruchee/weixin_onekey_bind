<?php

require __DIR__.'/weixin.php';


$params = array(
    'account'  => 'zhangsan',
    'password' => '123456',
    'url'      => 'http://zhangsan.duapp.com',
    'token'    => 'zhangsan'
);


$wx = new Weixin($params);
$wx->modify();
var_dump($wx->is_binded());
