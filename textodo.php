<?php
/**
 * Textodo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@unirgy.com so we can send you a copy immediately.
 *
 * @category   Unirgy
 * @package    Textodo
 * @copyright  Copyright (c) 2010 Unirgy LLC (http://www.unirgy.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @version    0.2.0
 */

/************** CONFIGURATION *****************/

error_reporting(E_ALL | E_STRICT);

$config = array(
    'db' => array(
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'dbname',
    ),
    'rate' => array(
        'query' => 300,
        'changes' => 1500,
    ),
    'login' => array(
        'session_cookie' => 'unirgy_textodo',
        'username_cookie' => 'unirgy_gtd_last_username',
        'username_expire' => 86400*30,
    ),
);

/*************** CONTROLLER *******************/

session_name($config['login']['session_cookie']);
session_start();

if (empty($_SESSION['user'])) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        login_form();
    } else {
        process_login($_POST['username'], $_POST['password']);
    }
    exit;
}

switch (empty($_GET['r']) ? '' : $_GET['r']) {
case '':
    main_page();
    break;

case 'ajax_search':
    fetch_lines();
    break;

case 'ajax_updates':
    apply_updates();
    break;

case 'logout':
    session_destroy();
    header("Location: ?");
    exit;

default:
    header('HTTP/1.0 404 Not Found');
    echo "<html><body><h1>Not Found</h1></body></html>";
    exit;
}

/******************* MODELS ***************/

function db_connect() {
    $config = $GLOBALS['config']['db'];
    $db = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    if (mysqli_connect_error()) {
        die('Connect Error ('.mysqli_connect_errno().') '.mysqli_connect_error());
    }
    return $db;
}

function process_login($username, $password) {
    setcookie($GLOBALS['config']['login']['username_cookie'], $username, time()+$GLOBALS['config']['login']['username_expire']);
    $db = db_connect();
    $result = mysqli_query($db, "SELECT * FROM textodo_user WHERE username='".addslashes($username)."' AND passhash='".md5($password)."'");
    if (!$result) {
        header("Location: ?error=".urlencode("Invalid login"));
        return;
    }
    while ($row = mysqli_fetch_assoc($result)) {
        $_SESSION['user'] = $row;
    }
    header("Location: ?");
}

function fetch_lines() {
    $db = db_connect();
    $out = '';
    if (!empty($_GET['query'])) {
        $query = preg_split("# +#", addslashes($_GET['query']));
        $whereArr = array();
        foreach ($query as $term) {
            if ($term==='') {
                continue;
            }
            $term = strtr($term, '+', ' ');
            if ($term[0]=='-') {
                $whereArr[] = "line NOT LIKE '%".substr($term, 1)."%'";
            } else {
                $whereArr[] = "line LIKE '%".$term."%'";
            }
        }
        $where = join(" AND ", $whereArr);
    }
    echo '<ul>';
    $result = mysqli_query($db, "SELECT * FROM textodo_lines WHERE user_id=".(int)$_SESSION['user']['id'].(!empty($where) ? " and (".$where.")" : "")." order by ifnull(priority, 10000)");
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<li><input type="text" name="line['.$row['id'].']" value="'.htmlspecialchars($row['line']).'" /></li>';
    }
    echo '<li><input class="new-line" type="text" name="line[-1]" value="" /></li></ul>';
}

function apply_updates() {
    if (empty($_POST['line'])) {
        return;
    }
    $newLineId = !empty($_POST['newLineId']) ? (int)$_POST['newLineId'] : -1;
    $db = db_connect();
    $newIds = array('"newLineId":'.$newLineId);
    foreach ($_POST['line'] as $id=>$line) {
        $priority = preg_match('#(^| )\\^([0-9]+)\\b#', $line, $m) ? (int)$m[2] : 'null';
        if ($priority<0) {
            $priority = 10000-$priority;
        }
        if ($id<=0) { // new
            mysqli_query($db, "INSERT INTO textodo_lines (user_id, line, priority) VALUES ('".(int)$_SESSION['user']['id']."','".addslashes($line)."', {$priority})");
            $newIds[] = '"line['.$id.']":"line['.mysqli_insert_id($db).']"';
        } elseif ($line=='') { // delete
            mysqli_query($db, "DELETE FROM textodo_lines WHERE id='".(int)$id."'");
            $newLineId--;
            $newIds[] = '"line['.$id.']":"line['.$newLineId.']"';
        } else { // update
            mysqli_query($db, "UPDATE textodo_lines SET line='".addslashes($line)."', `priority`={$priority} where id='".(int)$id."'");
        }
    }
    echo '{'.join(',', $newIds).'}';
}

/*********************** VIEWS ***********************/

function html_header() {
    $rateConfig = $GLOBALS['config']['rate'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>TEXTODO</title>
    <style type="text/css">
.error-msg { border:solid 1px #F00; background:#FCC; padding:5px; }

* { font-family:Arial; font-size:10pt; }

#login-form input { line-height:1.5em; height:1.5em; font-size:12pt; }

fieldset { border:0; }
#query { width:200px; border:solid 1px #888; }

#result-form ul { list-style-type:none; margin:0; padding:0; }
#result-form input { border-style:dotted; border-color:#888; border-width:0 0 1px 0; width:100%; line-height:1.5em; height:1.5em; margin:0; }
/*#result-form input.new-line { background:#DDD; }*/

#user-info { text-align:center; }
    </style>
    <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
    <script type="text/javascript">
$(document).ready(function() {
    var queryRate = '<?php echo $rateConfig['query'] ?>', changesRate = '<?php echo $rateConfig['changes'] ?>';
    var queryCache = '', linesCache = {}, newLineId = -1;

    var fragment = location.href.match(/#(.*)$/);
    if (fragment) {
        var args = fragment[1].replace(/\+/g, ' ').split('&');
        for (var i=0; i<args.length; i++) {
            var pair = args[i].split('=');
            var name = decodeURIComponent(pair[0]);
            var value = pair.length==2 ? decodeURIComponent(pair[1]) : name;
            $('#search-form input[name="'+name+'"]').val(value);
        }
    }

    $('#search-form,#result-form').submit(function(e) { e.preventDefault(); return false; });

    setTimeout(submitQuery, queryRate);
    setTimeout(submitChanges, changesRate);

    function submitQuery() {
        var query = $('#search-form').serialize();
        if (query!=queryCache) {
            queryCache = query;
            location.href = '#'+query;
            $('#result-container').load('?r=ajax_search', query, function () {
                newLineId = -1;
                linesCache = {};
                linesChanged = {};
                $('#result-container input').each(function (idx) {
                    linesCache[this.name] = this.value;
                }).keydown(observeLineKeyDown);
                setTimeout(submitQuery, queryRate);
            });
        } else {
            setTimeout(submitQuery, queryRate);
        }
    }

    function observeLineKeyDown(e) {
        switch (e.keyCode) {
        case 38:
            var li = $(e.target).parent('li');
            if (!li.is(':first-child')) {
                li.prev().children('input').focus();
            } else {
                $('#query').focus();
            }
            break;

        case 40: case 13:
            var li = $(e.target).parent('li');
            if (!li.is(':last-child')) {
                li.next().children('input').focus();
            }
            break;
        }
    }

    function submitChanges() {
        var linesChanged = {newLineId:newLineId}, newValue, flag = false, i;
        for (i in linesCache) {
            newValue = $('input[name="'+i+'"]').val();
            if (newValue!=linesCache[i]) {
                linesChanged[i] = newValue;
                linesCache[i] = newValue;
                flag = true;
            }
        }
        if (flag) {
            $.post('?r=ajax_updates', linesChanged, function (data) {
                var result = $.parseJSON(data), lineEl, newLineEl;
                for (i in result) {
                    if (i=='newLineId') {
                        newLineId = result[i];
                        continue;
                    }
                    lineEl = $('input[name="'+i+'"]');
                    lineEl.attr('name', result[i]);
                    linesCache[result[i]] = linesCache[i];
                    delete linesCache[i];
                    if (lineEl.val()!='' && lineEl.parent('li').is(':last-child')) {
                        newLineId--;
                        newLineEl = $('<li><input class="new-line" type="text" name="line['+newLineId+']" value="" /></li>');
                        newLineEl.children('input').keydown(observeLineKeyDown);
                        lineEl.parent('li').after(newLineEl);
                        linesCache['line['+newLineId+']'] = '';
                    }
                }
                setTimeout(submitChanges, changesRate);
            });
        } else {
            setTimeout(submitChanges, changesRate);
        }
    }
});
    </script>
</head>
<body>
<?php
}

function login_form() {
    $cookieName = $GLOBALS['config']['login']['username_cookie'];
    html_header();
?>
<?php if (!empty($_REQUEST['error'])): ?>
    <div class="error-msg"><?php echo htmlspecialchars($_REQUEST['error']) ?></div>
<?php endif ?>
    <form id="login-form" method="post">
        <fieldset>
            <p><label for="username">User name:<br /><input type="text" name="username" value="<?php echo !empty($_COOKIE[$cookieName]) ? htmlspecialchars($_COOKIE[$cookieName]) : '' ?>" /></label></p>
            <p><label for="password">Password:<br /><input type="password" name="password" value="" /></label></p>
            <p><input type="submit" value="Login" /></p>
        </fieldset>
    </form>
<?php
    html_footer();
}

function main_page() {
    html_header();
?>
    <form id="search-form">
        <fieldset>
            <label for="query">Filter:&nbsp;<input type="text" name="query" id="query" /></label>
        </fieldset>
    </form>

    <form id="result-form">
        <fieldset>
            <div id="result-container"></div>
        </fieldset>
    </form>
    <div id="user-info"><?php echo $_SESSION['user']['username'] ?> / <a href="?r=logout">Log out</a></div>
<?php
    html_footer();
}

function html_footer() {
?>
</body>
</html>
<?php
}
