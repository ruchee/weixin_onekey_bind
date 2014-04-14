<?php


/**
 * 主要功能：
 * 1、一键绑定和配置微信公众平台
 * 2、检测是否绑定成功
 */


/**
 * 使用方法：
 * require __DIR__.'/weixin.php';
 *
 * $params = array(
 *     'account'  => 'zhangsan',
 *     'password' => '123456',
 *     'url'      => 'http://zhangsan.duapp.com',
 *     'token'    => 'zhangsan'
 * );
 *
 * $wx = new Weixin($params);
 * $wx->modify();
 * echo $wx->is_binded();
 */


require_once __DIR__.'/simple_html_dom.php';


class Weixin {

    private $account   = ''; // 用户账号
    private $password  = ''; // 用户密码
    private $cfg_url   = ''; // 响应服务器地址
    private $cfg_token = ''; // 用户自定义TOKEN

    private $host     = 'mp.weixin.qq.com';  //主机
    private $origin   = 'https://mp.weixin.qq.com';  // 起源地址
    private $referer  = "https://mp.weixin.qq.com";  // 引用地址
    private $post_url = 'https://mp.weixin.qq.com/cgi-bin/login?lang=zh_CN';  // 登录提交地址

    // 公众平台后台编辑模式页面地址
    private $admin_edit_url = 'https://mp.weixin.qq.com/advanced/advanced?action=edit&t=advanced/edit&lang=zh_CN';
    // 公众平台后台开发模式页面地址
    private $admin_dev_url = 'https://mp.weixin.qq.com/advanced/advanced?action=dev&t=advanced/dev&lang=zh_CN';
    // 开关编辑/开发模式的请求地址
    private $admin_switch_url = 'https://mp.weixin.qq.com/misc/skeyform?form=advancedswitchform&lang=zh_CN';
    // 修改服务器配置请求地址
    private $admin_update_cfg_url = 'https://mp.weixin.qq.com/advanced/callbackprofile?t=ajax-response&lang=zh_CN';

    private $token      = 0;       // 返回的TOKEN
    private $send_data  = array(); // 提交的数据
    private $get_header = 0;       // 是否显示Header信息
    private $user_agent = 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23.0';


    public function __construct ($params) {
        $this->account  = @$params['account'];
        $this->password = @$params['password'];
        if (!$this->account || !$this->password) {
            $this->exit_msg('登录信息不完整');
        }

        $this->cfg_url = @$params['url'];
        $this->cfg_token = @$params['token'];
        if (!$this->cfg_url || !$this->cfg_token) {
            $this->exit_msg('服务器配置信息不完整');
        }

        $this->login();

        $this->open();
    }


    /**
     * 判断是否绑定成功
     */
    public function is_binded () {
        $url = $this->admin_dev_url."&token={$this->token}";
        $html = $this->vget($url);
        $frm_input_box = $html->find('div.frm_input_box');

        // 从微信后台获取到的当前URL和TOKEN
        $current_url = @$frm_input_box[0]->plaintext;
        $current_token = @$frm_input_box[1]->plaintext;

        if (trim($current_url) == trim($this->cfg_url) &&
            trim($current_token) == trim($this->cfg_token)) {
            return true;
        }
        return false;
    }


    /**
     * 修改服务器配置
     */
    public function modify () {
        $this->send_data = array(
            'url' => $this->cfg_url,
            'callback_token' => $this->cfg_token,
            'f' => 'json'
        );
        $this->get_header = 1;

        // 获取返回的数据
        $result = explode("\n", $this->curl_post($this->admin_update_cfg_url."&token={$this->token}"));
        // 返回的状态数组
        $retarr = @json_decode($result[count($result)-1], true);
        if (!$retarr || !is_array($retarr)) {
            $this->exit_msg('修改服务器配置失败');
        }
    }


    /**
     * 模拟登录
     */
    private function login () {
        $this->send_data = array(
            'username' => $this->account,
            'pwd' => md5($this->password),
            'imgcode' => '',
            'f' => 'json'
        );
        $this->get_header = 1;

        // 获取登录后返回的数据
        $result = explode("\n", $this->curl_post($this->post_url));
        // 返回的状态数组
        $retarr = @json_decode($result[count($result)-1], true);
        if (!$retarr || !is_array($retarr)) {
            $this->exit_msg('内部登录错误');
        }

        $re_code = $retarr['base_resp']['ret'];     // 返回的状态码
        $err_msg = $retarr['base_resp']['err_msg']; // 返回的错误信息
        $red_url = @$retarr['redirect_url'];        // 返回的回跳地址

        // 出现错误
        if ($re_code != 0) {
            switch ($re_code) {
            case "-1":
                $this->exit_msg('系统错误，请稍候重试', 1, -1);
            case "-2":
                $this->exit_msg('账号或密码错误', 1, -2);
            case "-23":
                $this->exit_msg('您输入的帐号或者密码不正确，请重新输入', 1, -23);
            case "-21":
                $this->exit_msg('不存在该帐户', 1, -21);
            case "-7":
                $this->exit_msg('您目前处于访问受限状态', 1, -7);
            case "-8":
                $this->exit_msg('请输入图中的验证码', 1, -8);
            case "-27":
                $this->exit_msg('您输入的验证码不正确，请重新输入', 1, -27);
            case "-26":
                $this->exit_msg('该公众会议号已经过期，无法再登录使用', 1, -26);
            case "-25":
                $this->exit_msg('海外帐号请在公众平台海外版登录', 1, -25);
            default:
                $this->exit_msg('未知的返回', 1, $re_code);
            }
        }

        // 获取TOKEN
        preg_match('/token=(\d+)/i', $red_url, $token);
        $this->token = intval($token[1]);

        // 获取COOKIE
        foreach ($result as $item) {
            if (preg_match('/^set-cookie:[\s]+([^=]+)=([^;]+)/i', $item, $cookie)) {
                $this->cookie .= "{$cookie[1]}={$cookie[2]}; ";
            }
        }
    }


    /**
     * 开启服务
     */
    private function open () {
        // 关闭编辑模式
        $this->close_edit_mode();
        // 开启开发模式
        $this->open_develop_mode();
    }


    /**
     * 关闭编辑模式
     */
    private function close_edit_mode () {
        $this->send_data = array(
            'flag' => 1,
            'type' => 1,
            'token' => $this->token,
            'f' => 'json'
        );
        $this->get_header = 1;

        // 获取返回的数据
        $result = explode("\n", $this->curl_post($this->admin_switch_url."&token={$this->token}"));
        // 返回的状态数组
        $retarr = @json_decode($result[count($result)-1], true);
        if (!$retarr || !is_array($retarr)) {
            $this->exit_msg('关闭编辑模式失败');
        }
    }


    /**
     * 打开开发模式
     */
    private function open_develop_mode () {
        $this->send_data = array(
            'flag' => 1,
            'type' => 2,
            'token' => $this->token,
            'f' => 'json'
        );
        $this->get_header = 1;

        // 获取返回的数据
        $result = explode("\n", $this->curl_post($this->admin_switch_url."&token={$this->token}"));
        // 返回的状态数组
        $retarr = @json_decode($result[count($result)-1], true);
        if (!$retarr || !is_array($retarr)) {
            $this->exit_msg('打开开发模式失败');
        }
    }


    /**
     * CURL模拟POST请求
     */
    private function curl_post ($url) {
        $header = array(
            'Accept:*/*',
            'Accept-Charset:GBK,utf-8;q=0.7,*;q=0.3',
            'Accept-Encoding:gzip,deflate,sdch',
            'Accept-Language:zh-CN,zh;q=0.8',
            'Connection:keep-alive',
            "Host:{$this->host}",
            "Origin:{$this->origin}",
            "Referer:{$this->referer}",
            'X-Requested-With:XMLHttpRequest'
        );

        $curl = curl_init();                                      // 启动一个curl会话
        curl_setopt($curl, CURLOPT_URL, $url);                    // 要访问的地址
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);          // 设置HTTP头字段的数组
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);            // 对认证证书来源的检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 1);            // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_SSLVERSION, 3);                // 指定SSL版本
        curl_setopt($curl, CURLOPT_USERAGENT, $this->user_agent); // 模拟用户使用的浏览器
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);            // 使用自动跳转
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1);               // 自动设置Referer
        curl_setopt($curl, CURLOPT_POST, 1);                      // 发送一个常规的Post请求
        curl_setopt($curl, CURLOPT_POSTFIELDS, $this->send_data); // Post提交的数据包
        curl_setopt($curl, CURLOPT_COOKIE, $this->cookie);        // 读取储存的Cookie信息
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);                  // 设置超时限制防止死循环
        curl_setopt($curl, CURLOPT_HEADER, $this->get_header);    // 显示返回的Header区域内容
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);            // 获取的信息以文件流的形式返回
        $result = curl_exec($curl);                               // 执行一个curl会话
        if (curl_errno($curl)) {
            $this->exit_msg(curl_error($curl));
        }
        curl_close($curl);                                        // 关闭curl

        return $result;
    }


    /**
     * CURL获取网页内容
     */
    private function vget ($url) {
        $curl = curl_init();                                      // 启动一个CURL会话
        curl_setopt($curl, CURLOPT_URL, $url);                    // 要访问的地址
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);            // 对认证证书来源的检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 1);            // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_SSLVERSION, 3);                // 指定SSL版本
        curl_setopt($curl, CURLOPT_USERAGENT, $this->user_agent); // 模拟用户使用的浏览器
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);            // 使用自动跳转
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1);               // 自动设置Referer
        curl_setopt($curl, CURLOPT_HTTPGET, 1);                   // 发送一个常规的GET请求
        curl_setopt($curl, CURLOPT_COOKIE, $this->cookie);        // 读取上面所储存的Cookie信息
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);                  // 设置超时限制防止死循环
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);            // 获取的信息以文件流的形式返回
        $result = curl_exec($curl);                               // 执行操作
        if (curl_errno($curl)) {
            $this->exit_msg(curl_error($curl));
        }
        curl_close($curl);                                        // 关闭curl

        // 调用simple_html_dom
        $html= str_get_html($result);

        return $html;
    }


    /**
     * 打印JSON格式的消息
     */
    private function echo_msg ($msg, $status = 0, $errcode = 0) {
        echo json_encode(array(
            'status'  => $status,
            'errCode' => $errcode,
            'msg'     => $msg
        ));
    }


    /**
     * 打印错误消息并退出
     */
    private function exit_msg ($msg, $status = 1, $errcode = 0) {
        $this->echo_msg($msg, $status, $errcode);
        exit();
    }

}
