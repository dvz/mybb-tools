<?php

/*
>
<p id="fallback_message">
    Upload this file to the forum's main directory, and open it in a web browser (<code>example.com/_parser-debug.php</code>).
</p>
<style>
body { font-size: 0; }
#fallback_message { font-size: initial; }
</style>
<!--
*/

define('TOOL_TITLE', 'Parser Validation Debug');
define('TOOL_VERSION', '1.0');

function integrated()
{
    return file_exists('global.php');
}

function authorized()
{
    return is_super_admin($GLOBALS['mybb']->user['uid']);
}

function getPostParsingOutput($post, $forum)
{
    global $lang;

    require_once MYBB_ROOT . 'inc/class_parser.php';

    $parser = new postParser();

    $parser->output_validation_policy = postParser::VALIDATION_DISABLE;

    if (!$post['username']) {
        $post['username'] = $lang->guest;
    }

    $parser_options = array();

    $parser_options['allow_html'] = $forum['allowhtml'];
    $parser_options['allow_mycode'] = $forum['allowmycode'];
    $parser_options['allow_smilies'] = $forum['allowsmilies'];
    $parser_options['allow_imgcode'] = $forum['allowimgcode'];
    $parser_options['allow_videocode'] = $forum['allowvideocode'];
    $parser_options['filter_badwords'] = 1;
    $parser_options['me_username'] = htmlspecialchars($post['username']);

    $output = $parser->parse_message($post['message'], $parser_options);

    return $output;
}

function getXmlErrors($output)
{
    $ignored_error_codes = array(
        // entities may be broken through smilie parsing; cache_smilies() method workaround doesn't cover all entities
        'XML_ERR_INVALID_DEC_CHARREF' => 7,
        'XML_ERR_INVALID_CHAR' => 9,

        'XML_ERR_UNDECLARED_ENTITY' => 26, // unrecognized HTML entities
        'XML_ERR_ATTRIBUTE_WITHOUT_VALUE' => 41,
        'XML_ERR_TAG_NAME_MISMATCH' => 76, // the parser may output tags closed in different levels and siblings
    );

    libxml_use_internal_errors(true);
    @libxml_disable_entity_loader(true);

    simplexml_load_string('<root>'.$output.'</root>', 'SimpleXMLElement', 524288 /* LIBXML_PARSEHUGE */);

    $libxmlErrors = libxml_get_errors();

    libxml_use_internal_errors(false);

    if(
        $libxmlErrors &&
        array_diff(
            array_column($libxmlErrors, 'code'),
            $ignored_error_codes
        )
    ) {
        $errors = array();

        foreach ($libxmlErrors as $libxmlError) {
            $errors[] = array(
                'level' => $libxmlError->level,
                'code' => $libxmlError->code,
                'column' => $libxmlError->column,
                'message' => $libxmlError->message,
                'line' => $libxmlError->line,
            );
        }

        return $errors;
    } else {
        return array();
    }
}

function getEntriesFromRawErrors($string)
{
    $entries = array();

    preg_match_all("~
        <error>\s*
            (<dateline>(?<time>\d+)</dateline>\s*)?
            (<script>.*?</script>\s*)?
            (<line>\d+</line>\s*)?
            (<type>\d+</type>\s*)?
            (<friendly_type>.*?</friendly_type>\s*)?
            <message>Parser\ output\ validation\ failed\.\s*
                array\ \(\s*
                    'sourceHtmlEntities'\ =>\ '(?<sourceHtmlEntities>.*?)',\s*
                    'outputHtmlEntities'\ =>\ '(?<outputHtmlEntities>.*?)',\s*
                    'errors'\ =>\ \s*
                        array\ \(\s*
                            (?<errors>.*?)
                        \),\s*
                \)
            </message>\s*
            (<back_trace>.*?</back_trace>\s*)?
            .*?
        </error>
    ~iusx", $string, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $entry = array(
            'time' => $match['time'],
            'source' => htmlspecialchars_decode(stripslashes($match['sourceHtmlEntities'])),
            'output' => htmlspecialchars_decode(stripslashes($match['outputHtmlEntities'])),
            'errors' => array(),
        );

        preg_match_all("~
            LibXMLError::__set_state\(array\(\s*
               'level'\ =>\ (?<level>\d+),\s*
               'code'\ =>\ (?<code>\d+),\s*
               'column'\ =>\ (?<column>\d+),\s*
               'message'\ =>\ '(?<message>.*?)',\s*
               'file'\ =>\ '',\s*
               'line'\ =>\ (?<line>\d+),\s*
            \)\),\s*
        ~iusx", $match['errors'], $entryErrorMatches, PREG_SET_ORDER);

        foreach ($entryErrorMatches as $entryErrorMatch) {
            $entry['errors'][] = array(
                'level' => $entryErrorMatch['level'],
                'code' => $entryErrorMatch['code'],
                'column' => $entryErrorMatch['column'],
                'message' => stripslashes($entryErrorMatch['message']),
                'line' => $entryErrorMatch['line'],
            );
        }

        $entries[] = $entry;
    }

    return $entries;
}

function getLastVisiblePostId()
{
    global $db;

    return $db->fetch_field(
        $db->simple_select('posts', 'pid', 'visible=1', array(
            'order_by' => 'pid',
            'order_dir' => 'desc',
        )),
        'pid'
    );
}

function renderEntries($entries)
{
    $html = '';

    $i = 0;

    foreach ($entries as $entry) {
        $i++;

        $mycodeEscaped = htmlspecialchars($entry['source']);
        $outputEscaped = htmlspecialchars($entry['output']);
        $outputEncoded = base64_encode($entry['output']);
        $errorCount = count($entry['errors']);

        $meta = '';

        if (isset($entry['entryType'], $entry['entryId'])) {
            $meta = 'Validation results for ' . htmlspecialchars($entry['entryType']) . ' #' . htmlspecialchars($entry['entryId']);
        }

        if (!empty($entry['time'])) {
            $meta = 'Validation failure from <span>' . htmlspecialchars(date('r', (int)$entry['time'])) . ' (' . htmlspecialchars($entry['time']) . ')</span>';
        }

        if ($errorCount === 0) {
            $results = <<<HTML
    <section class="type--success">
        <p class="title">Validation Successful</p>
        <p class="body">This content validates correctly.</p>
    </section>
HTML;
        } else {
            $errorsEscaped = '';

            foreach ($entry['errors'] as $error) {
                $messageEscaped = htmlspecialchars($error['message']);
                $codeEscaped = (int)$error['code'];
                $lineEscaped = (int)$error['line'];
                $columnEscaped = (int)$error['column'];

                switch ($error['level']) {
                    case LIBXML_ERR_WARNING:
                        $level = 'warning';
                        break;
                    case LIBXML_ERR_ERROR:
                        $level = 'Error';
                        break;
                    case LIBXML_ERR_FATAL:
                        $level = 'Fatal error';
                        break;
                }

                $errorsEscaped .= <<<HTML
        <li>
            <p class="error-content">
                <code>{$messageEscaped}</code>
            </p>
            <p>{$level} ($codeEscaped) &middot; line {$lineEscaped}:{$columnEscaped}</p>
        </li>
HTML;
            }

            $lineHighlights = '';

            if (isset($entry['errors'][0]['line'])) {
                $lineHighlights = (int)$entry['errors'][0]['line'];
            }

            if (!empty($entry['time'])) {
                $errorsTitle = 'Logged XML Validation Errors';
            } else {
                $errorsTitle = 'XML Validation Errors';
            }

            $results = <<<HTML
    <section class="type--errors" data-count="{$errorCount}">
        <p class="title">{$errorsTitle} ({$errorCount})</p>
        <ol class="errors">
            {$errorsEscaped}
        </ol>
    </section>
HTML;
        }

        $html .= <<<HTML
<article>
    <p class="meta">
        <strong>{$i}.</strong>
        {$meta}
    </p>
    <div class="sides">
        <section class="side type-input">
            <p class="title select-control">MyCode Input</p>
            <div class="code">
                <pre><code class="language-bbcode">{$mycodeEscaped}</code></pre>
            </div>
        </section>
        <section class="side type--output">
            <p class="title select-control">HTML Output</p>
            <div class="code">
                <pre data-line="{$lineHighlights}" class="line-numbers"><code class="language-html">{$outputEscaped}</code></pre>
            </div>
        </section>
    </div>
    {$results}
    <section class="type--output">
        <p class="title select-control"><abbr title="Output errors may not be visible in formatted code">Formatted</abbr> HTML Output</p>
        <div class="code">
            <pre><code class="language-html" data-beautify="html" data-encoded="{$outputEncoded}"></code></pre>
        </div>
    </section>
</article>
HTML;
    }

    return $html;
}

function renderPage($mode, $data = array())
{
    $titleEscaped = htmlspecialchars(TOOL_TITLE);
    $versionEscaped = htmlspecialchars(TOOL_VERSION);
    $filenameEscaped = htmlspecialchars(basename(__FILE__));

    switch ($mode) {
        case 'RESULTS_ENTITY':
            $modeDetails = 'Board Content Diagnostics';
            break;
        case 'RESULTS_RAW':
            $modeDetails = count($data['entries']) . ' validation failures found in raw error log content submitted ' . date('r');
            break;
        case 'INPUT':
            $modeDetails = '';
    }

    if ($mode === 'INPUT') {
        if ($data['defaultPostId'] !== null) {
            $defaultPostId = htmlspecialchars($data['defaultPostId']);
        } else {
            $defaultPostId = '';
        }

        if (!$data['integrated']) {
            $postIdInput = '<p>No MyBB installation found in this directory.</p>';
        } elseif (!$data['authorized']) {
            $postIdInput = '<p>You must be logged in as a <a href="https://docs.mybb.com/1.8/administration/configuration-file/" rel="noopener">Super Administrator</a> to use this feature.</p>';
        } else {
            $postIdInput = <<<HTML
                    <p>Post ID:</p>
                    <input type="number" name="pid" value="{$defaultPostId}" />
                    <input type="submit" value="Analyze" />
HTML;
        }

        $content = <<<HTML
            <form action="" method="get">
                <fieldset>
                    <legend>Validate Post on This Board</legend>
                    {$postIdInput}
                </fieldset>
            </form>
            
            <form action="" method="post">
                <fieldset>
                    <legend>Parse Validation Logs</legend>
                    <p><code>error.log</code> entries:</p>
                    <textarea name="raw" placeholder="<error>...</error>"></textarea>
                    <input type="submit" value="Analyze" />
                </fieldset>
            </form>
HTML;
    } else {
        $entries = renderEntries($data['entries']);

        $content = <<<HTML
            <div class="content">
                {$entries}
            </div>
HTML;
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{$titleEscaped} ({$versionEscaped})</title>
        <link rel="stylesheet" href="?resource=css">
        
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism.min.css" integrity="sha512-tN7Ec6zAFaVSG3TpNAKtk4DOHNpSwKHxxrsiw4GHKESGPs5njn/0sMCUMl2svV4wo4BK/rCP7juYz+zx+l6oeQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.css" integrity="sha512-cbQXwDFK7lj2Fqfkuxbo5iD1dSbLlJGXGpfTDqbggqjHJeyzx88I3rfwjS38WJag/ihH7lzuGlGHpDBymLirZQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-highlight/prism-line-highlight.min.css" integrity="sha512-nXlJLUeqPMp1Q3+Bd8Qds8tXeRVQscMscwysJm821C++9w6WtsFbJjPenZ8cQVMXyqSAismveQJc0C1splFDCA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    </head>
    <body>
        <header>
            <a href="{$filenameEscaped}">
                <h1>{$titleEscaped} <span class="minor">({$versionEscaped})</span></h1>
            </a>
            <p class="status">{$modeDetails}</p>
        </header>
        {$content}

        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/prism.min.js" integrity="sha512-axJX7DJduStuBB8ePC8ryGzacZPr3rdLaIDZitiEgWWk2gsXxEFlm4UW0iNzj2h3wp5mOylgHAzBzM4nRSvTZA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.js" integrity="sha512-dubtf8xMHSQlExGRQ5R7toxHLgSDZ0K7AunqPWHXmJQ8XyVIG19S1T95gBxlAeGOK02P4Da2RTnQz0Za0H0ebQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-highlight/prism-line-highlight.min.js" integrity="sha512-io5Rc3awpBqWExsPf0YuVodLEMhllL50UhmNJY7DYR4riSgCDDPwGfmYbXP56ANuIWrPJumIw9AKZKKo5SUNOA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>        
        
        <script src="?resource=javascript"></script>
    </body>
</html>
HTML;

    return $html;
}

$resources = array(
    'css' => <<<CSS
* { margin: 0; }

body { font-family: sans-serif; font-size: 14px; }

header { padding: 10px; background-color: #007fd0; color: #FFF; }
header a { color: unset; text-decoration: unset; }
header h1 { font-weight: normal; }
header h1 .minor { color: rgba(255,255,255,0.5); }
header .status:not(:empty) { padding: 4px 0; }

.content { margin: 10px; }

fieldset { margin: 20px 10px; }
fieldset legend { font-weight: bold; }
textarea { margin-bottom: 4px; width: 100%; height: 200px; resize: vertical; }

article { margin-bottom: 40px; }
article .meta { padding: 4px; border-bottom: solid 2px #444; font-size: 1.2em; color: #444; }

section { background-color: rgba(100,100,100,0.05); }
.title { padding: 8px 6px 4px 6px; background-color: rgba(100,100,100,0.2); border-bottom: solid 2px rgba(100,100,100,0.2); font-size: 1.2em; }
.select-control { cursor: pointer; }
.body { padding: 10px; }

.sides { display: flex; }
.side { flex: 1; width: 50%; }
.side pre { height: 400px; }

.code { font-size: 0.9em; }
pre { margin: 0 !important /* PrismJS override */; max-height: 400px; resize: vertical; }
pre, code { white-space: pre-wrap !important /* PrismJS override */; word-break: break-word !important /* PrismJS override */; }

.errors li { padding: 10px; }
.errors li:not(:first-child) { opacity: 0.5; }
.errors li:not(:last-of-type) { border-bottom: solid 2px rgba(100,100,100,0.2); }
.error-content { margin-bottom: 4px; }

.type--errors { background-color: rgba(255,0,0,0.05); }
.type--success { background-color: rgba(0,255,0,0.05); }

section.type--errors[data-count="0"] { display: none; }
CSS,
    'javascript' => <<<JS
function indent(str) {
    const div = document.createElement('div');
    div.innerHTML = str.trim();

    return indentNode(div, 0).innerHTML.trim();
}

function indentNode(node, level) {
    let textNode;
    
    const indentBefore = new Array(level++ + 1).join('  ');
    const indentAfter = new Array(level - 1).join('  ');

    for (let i = 0; i < node.children.length; i++) {
        textNode = document.createTextNode('\\n' + indentBefore);
        node.insertBefore(textNode, node.children[i]);

        indentNode(node.children[i], level);

        if (node.lastElementChild === node.children[i]) {
            textNode = document.createTextNode('\\n' + indentAfter);
            node.appendChild(textNode);
        }
    }

    return node;
}

for (const e of document.querySelectorAll('code[data-encoded]')) {
    let encoded = e.getAttribute('data-encoded');
    
    e.textContent = indent(atob(encoded));
}

for (const e of document.querySelectorAll('section code')) {
    e.closest('section').querySelector('.select-control')?.addEventListener('click', ev => {
        const selection = window.getSelection();
        selection.removeAllRanges();
        
        const range = document.createRange();
        range.selectNodeContents(e);
        
        selection.addRange(range);
    });
}
JS,
);

header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/; script-src 'self' https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/");
header("Cross-Origin-Opener-Policy: same-origin");
header("Cross-Origin-Resource-Policy: same-origin");
header("Referrer-Policy: no-referrer");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

if (isset($_GET['resource']) && is_string($_GET['resource'])) {
    if (array_key_exists($_GET['resource'], $resources)) {
        header('Content-Type: text/' . $_GET['resource'] . '; charset=UTF-8');
        echo $resources[$_GET['resource']];
        exit;
    } else {
        http_response_code(404);
    }
} else {
    if (integrated()) {
        define('IN_MYBB', 1);
        define('NO_ONLINE', 1);

        require 'global.php';
    }

    if (isset($_GET['pid']) && is_string($_GET['pid'])) {
        if (!integrated()) {
            http_response_code(405);
            exit;
        }

        if (!authorized()) {
            http_response_code(403);
            exit;
        }

        $post = get_post((int)$_GET['pid']);

        if (!$post) {
            http_response_code(404);
            exit('Post not found');
        }

        $forum = get_forum($post['fid']);

        if (!$forum) {
            http_response_code(500);
            exit('Could not load forum data');
        }

        $parserOutput = getPostParsingOutput($post, $forum);
        $errors = getXmlErrors($parserOutput);

        $entry = array(
            'source' => $post['message'],
            'output' => $parserOutput,
            'errors' => $errors,
            'entryType' => 'post',
            'entryId' => $post['pid'],
        );

        $output = renderPage('RESULTS_ENTITY', array(
            'entries' => array($entry),
        ));

    } elseif (isset($_POST['raw']) && is_string($_POST['raw'])) {
        $entries = getEntriesFromRawErrors($_POST['raw']);

        $output = renderPage('RESULTS_RAW', array(
            'entries' => $entries,
        ));
    } else {
        $output = renderPage('INPUT', array(
            'integrated' => integrated(),
            'authorized' => integrated() && authorized(),
            'defaultPostId' => (integrated() && authorized()) ? getLastVisiblePostId() : null,
        ));
    }

    echo $output;
}
