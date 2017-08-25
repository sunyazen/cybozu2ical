<?php
//--------------------------------------------------------------------------------------------------------
// v10.4
//--------------------------------------------------------------------------------------------------------

date_default_timezone_set('Asia/Tokyo');
header("Content-Type: text/html; charset=utf-8");

//--------------------------------------------------------------------------------------------------------
// 設定
//--------------------------------------------------------------------------------------------------------
// サイボウズ
define("LOGIN_ID",   $argv[1]);                                    // ログインID
define("LOGIN_PASS", $argv[2]);                                    // ログインパス
define("UID", LOGIN_ID);                                           // 取得したいスケジュールのID（orユーザ名）

define('CYBOZU_URL', 'https://cybozuserver');                      // サイボウズのURL
define("CHAR_CODE",  'UTF-8');                                     // サイボウズの文字コード

// ical出力内容
define("SCHEDULE_TITLE", 'cybozu');                                // スケジュールタイトル

define("ICAL_FILE", 'C:/_root/tool/cron/cybozu2cal/cybozu_'.LOGIN_ID.'.ics');   //ical書込場所
define("SCHEDULE_MEMO", TRUE);                                    // メモ参照有無,個別取得で処理時間は長くなる
define("PRODUCT_ID", '-//cybozu2ical php//JP');                    // product identifier 任意文字列

// FTP転送
define("FTP_UPLOAD", TRUE);                                       // FTP転送有無
define("FTP_SERVER", 'ftpserver');                          // FTPサーバアドレス
define("FTP_PORT", '21');                                        // FTPサーバポート
define("FTP_USER", 'user');                                        // FTPユーザ名
define("FTP_PASS", 'pass');                                        // FTPパスワード
define("FTP_REMOTE_FILE", 'Web/gcal/cybozu_'.LOGIN_ID.'.ics');              // FTP転送先フォルダ＆ファイル名

// 処理内容
define("DEBUG_WRITE", FALSE);  // debugファイル出力有効時 'TRUE'
define("DEBUG_FILE", './debug.txt'); // debug出力ファイル場所指定

define("GET_MEMBER", FALSE);  // 詳細予定に記載のメンバー取得


//--------------------------------------------------------------------------------------------------------


// 文字列:$text 中より $key1 と $key2 に挟まれる文字列を返す
function getKeyword($text, $key1, $key2)
{
    $tmp = "";
    $tmp = substr(strstr($text,$key1), strlen($key1));
    $tmp = substr($tmp, 0, strpos($tmp, $key2));
    return $tmp;
}

// 文字列:$text 中より 先頭の $key1, 直後の $key2 を含むところまで除いた文字列を返す
function cutKeyword($text, $key1, $key2)
{
    $tmp = "";
    if (strpos($text, $key1) !== false)
    {
        // まず $key1 直後までを削除
        $tmp = substr(strstr($text,$key1), strlen($key1));
        // 次に $key2 直後までを削除
        if (strpos($tmp, $key2) !== false)
        {
           $tmp = substr(strstr($tmp,$key2), strlen($key2));
        } else {
            $tmp = $text; // $key1後に$key2がなければ原文を返す
        }
    } else {
        $tmp = $text; // $key1 がなければ原文を返す
    }
    return $tmp;
}

// 予定メモ取得
function getScheduleMemo($text)
{
    global $context; // global変数参照

    $tmp = "";
    if ( SCHEDULE_MEMO ) {
        // link url取得, 文字コード変換
        $wlink = html_entity_decode(getKeyword($text, 'href="ag.exe', '" title="'));
        $url = CYBOZU_URL.$wlink;

        //サイボウズからHTMLデータ取得
        $tmp = file_get_contents($url, false, stream_context_create($context));

        // メモ部分を取得
        $tmp = getKeyword($tmp, '<div id="scheduleMemo">', '</tt></div>');
        $tmp = html_entity_decode($tmp);

        // 空行/改行コード置換, ical(google)では 文字列 '\n' が改行扱い
        $tmp = str_replace("\n\r"," \\n", $tmp);  // 空行: \n
        $tmp = str_replace("\n"," \\n", $tmp);   // 改行: \n
        $tmp = str_replace("\r","", $tmp); // 削除
        $tmp = ltrim($tmp, ' \\n'); // 文字列先頭の改行を削除
        $tmp = strip_tags($tmp); // htmlタグを削除

        if ($tmp == " \\n") { $tmp = ""; }

    }
    return $tmp;
}

// debug用中間データ出力
function debugWrite($text)
{
    global $fp_dbg; // global変数参照

    if ( DEBUG_WRITE )
    {
        fwrite($fp_dbg, $text);
    }
}

//作成日時
$nowdate = gmdate("Ymd\THis\Z");

//POSTデータ組み立て
$data = array(
    "_System" => "login",
    "_Login" => "1",
    "LoginMethod" => "2",
    "_ID" => LOGIN_ID,
//    "_Account" => LOGIN_ID,
    "Password" => LOGIN_PASS
);
$data = http_build_query($data, "", "&");

//headerにセット
$header = array(
    "Content-Type: application/x-www-form-urlencoded",
    "Content-Length: ".strlen($data)
);
$context = array(
    "http" => array(
        "method"  => "POST",
        "header"  => implode("\r\n", $header),
        "content" => $data
    ),
    "ssl" => array(
        "verify_peer" => false,
        "verify_peer_name" => false
    )
);

//とりあえず個人月間スケジュール
if ( UID == "" ) {
    $url = CYBOZU_URL . '?page=ScheduleUserMonth';
} else {
    $url = CYBOZU_URL . '?page=ScheduleUserMonth&UID=' . UID;
}

//サイボウズからHTMLデータ取得
$work = file_get_contents($url, false, stream_context_create($context));
// echo $work;

// 予定に関連する部分のみ取得
$work = getKeyword($work, '<tbody id="um__body">', '</table>');

// スケジュールを格納する配列
$schedule_list = "";

// debug書込ファイルopen
$fp_dbg = "";
if ( DEBUG_WRITE) { $fp_dbg = fopen(DEBUG_FILE, "w"); }

// 全日 処理ループ
while ( TRUE ) {

    //日付の取得
    $wdate = getKeyword($work, '<span class="date">', '</span>');
    if ($wdate == "") {
        break;
    }
    debugWrite($wdate."\n");

    //日付のフォーマットを変更
    $date_sp = "";
    $date_sp = explode('/', $wdate);
    $schedule_date = Date('Y') . sprintf("%02d",$date_sp[0]) . sprintf("%02d",$date_sp[1]);
    //次の日も取得しとく
    $schedule_date_tommorow = "";
    $schedule_date_tommorow = Date('Ymd', mktime(0, 0, 0, (int)$date_sp[0], (int)((int)$date_sp[1] + 1), (int)Date('Y') ) );

    //日付部分までをカット
    $work = cutKeyword($work, '<span class="date">', '</span>');

    // 1日 処理ループ
    while ( TRUE ) {

        // 個別eventがある場合
        $pos_event  = strpos($work, '<div class="eventLink');
        $pos_date   = strpos($work, '<span class="date">');
        if ( ($pos_event !== false) && ($pos_event < $pos_date) )
        {
            // 個別情報取得
            $separate_info = getKeyword($work, '<div class="eventLink', '<span class="date">');

            // スケジュールIDを取得, event識別子: 'e' 追加
            $schedule_id = 'e'.getKeyword($separate_info, 'scheduleMarkTitle0" name="event', '">');

            $detail_url = "";
            $detail_work = "";
            if ( GET_MEMBER ) {
                // 予定詳細URLを取得
                $detail_url = CYBOZU_URL.getKeyword($separate_info, 'ag.exe', '" title');
                $detail_url = str_replace('&amp;', '&', $detail_url);

                $detail_work = file_get_contents($detail_url, false, stream_context_create($context));
                $detail_work = getKeyword($detail_work, 'name="MemberFunction"', '</td>');

                debugWrite("dtailURL  : ".$detail_url."\n");
                debugWrite("dtail     : ".$detail_work."\n");

            }

            // メモを取得
            $schedule_memo = getScheduleMemo($separate_info);

            // スケジュールタイトルを取得
            $schedule_title = getKeyword($separate_info, 'title="', '">');

            // 時刻指定を取得
            $eventTime = getKeyword($separate_info, 'class="eventDateTime">', '&nbsp;');

            // スケジュール年を取得
            $eventYear = getKeyword($separate_info, ';Date=da.', '.');

            // 時間情報取得・整形
            if ( $eventTime != "" ) {  // 時刻指定あり
                $time_sp = "";
                $time_sp = explode('-', $eventTime);
                $from_sp = explode(':', @$time_sp[0]);
                if (@$time_sp[1] != "") {  // 終了時刻指定あり
                    $to_sp   = explode(':', @$time_sp[1]);
                } else {  // 終了時刻指定なし
                    $to_sp = $from_sp;
                }

                // 日時をUTCに変換
                // mktime(hour, min, sec, month, day, year)
                $schedule_from        = 'DTSTART:' . gmdate("Ymd\THis\Z", mktime((int)$from_sp[0], (int)@$from_sp[1], 0, (int)$date_sp[0], (int)$date_sp[1], (int)$eventYear ) );
                $schedule_to          = 'DTEND:' . gmdate("Ymd\THis\Z", mktime((int)$to_sp[0], (int)@$to_sp[1], 0, (int)$date_sp[0], (int)$date_sp[1], (int)$eventYear ) );

            } else { // 時刻指定なし = 終日
                $schedule_from        = 'DTSTART;VALUE=DATE:' . $schedule_date;
                $schedule_to          = 'DTEND;VALUE=DATE:' . $schedule_date_tommorow;
            }

            // 配列に格納
            $schedule_list[] = Array('id'          => $schedule_id,
                                     'description' => $schedule_memo,
                                     'dtstart'     => $schedule_from,
                                     'dtend'       => $schedule_to,
                                     'summary'     => $schedule_title);

            // for debug
            debugWrite("id        : ".$schedule_id."\n");
            debugWrite("eventTime : ".$eventTime."\n");
            debugWrite("dtstart   : ".$schedule_from."\n");
            debugWrite("dtend     : ".$schedule_to."\n");
            debugWrite("summary   : ".$schedule_title."\n");
            debugWrite("memo      : ".$schedule_memo."\n");

            // 処理済みイベントを削除
            $work = cutKeyword($work, '<div class="eventLink', '</div>');

        }



        // バナーがある場合
        $pos_banner = strpos($work, '<td class="banner');
        $pos_date   = strpos($work, '<span class="date">');
        if ( ($pos_banner !== false) && ($pos_banner < $pos_date) )
        {
            // 個別情報取得
            $separate_info = getKeyword($work, '<td class="banner', '</span>');

            //スケジュールIDを取得, banner識別子: 'b' 追加
            $schedule_id = 'b'.getKeyword($separate_info, 'sEID=', '&amp;');

            // メモを取得
            $schedule_memo = getScheduleMemo($separate_info);

            // スケジュールタイトルを取得
            $schedule_title = getKeyword($separate_info, 'title="', '">');

            // バナー日数取得
            $banner_days = getKeyword($separate_info, 'colspan="', '">');

            // バナー開始日取得
            $banner_date = explode('.', getKeyword($separate_info, ';Date=da.', '&amp'));
            $banner_from = Date('Ymd', mktime(0, 0, 0, (int)$banner_date[1], (int)$banner_date[2], (int)$banner_date[0]));
            $banner_to   = Date('Ymd', mktime(0, 0, 0, (int)$banner_date[1], (int)((int)$banner_date[2] + (int)$banner_days), (int)$banner_date[0]));

            $schedule_from        = 'DTSTART;VALUE=DATE:' . $banner_from;
            $schedule_to          = 'DTEND;VALUE=DATE:' . $banner_to;

            // 配列に格納
            $schedule_list[] = Array('id'          => $schedule_id,
                                     'description' => $schedule_memo,
                                     'dtstart'     => $schedule_from,
                                     'dtend'       => $schedule_to,
                                     'summary'     => $schedule_title);

            // for debug
            debugWrite("id      : ".$schedule_id."\n");
            debugWrite("dtstart : ".$schedule_from."\n");
            debugWrite("days    : ".$banner_days."\n");
            debugWrite("dtend   : ".$schedule_to."\n");
            debugWrite("summary : ".$schedule_title."\n");
            debugWrite("memo    : ".$schedule_memo."\n");

            // 処理済みイベントを削除
            $work = cutKeyword($work, '<td class="banner', '</span>');

        }

        // event/bannerの有無＆位置チェック もうなければ1日ループを抜ける
        $pos_event  = strpos($work, '<div class="eventLink');
        $pos_banner = strpos($work, '<td class="banner');
        $pos_date   = strpos($work, '<span class="date">');
        if ((( $pos_event === false ) || ($pos_event > $pos_date)) && (( $pos_banner === false ) || ($pos_banner > $pos_date)))
        {
            break;
        }

    }

}

// debug書込ファイルclose
if ( DEBUG_WRITE) { fclose($fp_dbg); }

// ---------------------------------------------
// ファイル出力
// http://designpapa.net/archives/665
// ---------------------------------------------

$fp = fopen(ICAL_FILE, "w");
header('Content-Type: text/calendar; charset=utf-8');
fwrite($fp, 'BEGIN:VCALENDAR'. "\n");
fwrite($fp, 'PRODID:' . PRODUCT_ID. "\n");
fwrite($fp, 'VERSION:2.0'. "\n");
fwrite($fp, 'CALSCALE:GREGORIAN'. "\n");
fwrite($fp, 'METHOD:PUBLISH'. "\n");
fwrite($fp, 'X-WR-CALNAME:' . SCHEDULE_TITLE . "\n");
fwrite($fp, 'X-WR-TIMEZONE:Asia/Tokyo'. "\n");

if ( !empty($schedule_list) ) {
    foreach ( $schedule_list as $key => $vale ) {
    fwrite($fp, 'BEGIN:VEVENT'. "\n");
    fwrite($fp, $vale['dtstart'] . "\n");
    fwrite($fp, $vale['dtend'] . "\n");
    fwrite($fp, 'DTSTAMP:' . $nowdate . "\n");
    $dtstart_sp = explode(':', $vale['dtstart']);
    fwrite($fp, 'UID:' . SCHEDULE_TITLE . '-' . $vale['id'] . '-' . $dtstart_sp[1] . "\n");
    fwrite($fp, 'DESCRIPTION:' . $vale['description']. "\n");
    fwrite($fp, 'SUMMARY:'. $vale['summary']. "\n");
    fwrite($fp, 'END:VEVENT'. "\n");
    }
}

fwrite($fp, 'END:VCALENDAR'. "\n");
fclose($fp);

// FTP upload
// ---------------------------------------------
// ---------------------------------------------

if ( FTP_UPLOAD )
{
    // connect
    $conn_id = ftp_connect(FTP_SERVER, FTP_PORT);

    // login
    $login_result = ftp_login($conn_id, FTP_USER, FTP_PASS);

    // confirm
    if ((!$conn_id) || (!$login_result)) {
        break;
    }

    // passive mode
    $res = ftp_pasv($conn_id, true);

    // upload
    if (!ftp_put($conn_id, FTP_REMOTE_FILE, ICAL_FILE, FTP_ASCII)) {
    exit;
    }

}

?>