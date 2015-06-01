<?php
/**
 * Command Line Interface (CLI) Plugin
 *     Typeset transcripts of interactive sessions with mimumum effort.
 * Syntax:
 *     <cli prompt="$ " continue="> " comment="#">
 *     user@host:~/somedir $ ls \
 *     > # List directory
 *     file1 file2
 *     </cli>
 *   prompt --- [optional] prompt character used. '$ ' is default - note the space.
 *   comment --- [optional] comment character used. '#' is default - note no space.
 *   continue --- [optional] regex of shell continuation '/^> /' is the default.
 *   The defaults above match Bourne shell ${PS1} and ${PS2} prompts and comment
 *
 * Acknowledgements:
 *  Borrows heavily from the boxes plugin!
 *  Support for continuation added by Andy Webber
 *  Improved parsing added by Stephane Chazelas
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Chris P. Jobling <C.P.Jobling@Swansea.ac.uk>
 */
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
 
/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_cli extends DokuWiki_Syntax_Plugin {
    
    var $prompt_str = '# ';
    var $prompt_cont = '/^> /'; // this is a regex
    var $prompt_continues = false;
    var $comment_str = '#';
    
    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Chris P. Jobling; Stephan Chazelas; Andy Webber',
            'email'  => 'C.P.Jobling@Swansea.ac.uk',
            'date'   => '2008-13-02',
            'name'   => 'Command Line Interface (CLI) Plugin',
            'desc'   => 'Renders transcripts of command line interactions, e.g. for shell and dynamic language interpretor tutorials',
            'url'    => 'http://eehope.swan.ac.uk/dokuwiki/plugins:cli',
        );
    }
 
    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'protected';
    }
 
    /**
     * What kind of syntax do we allow (optional)
     */
//    function getAllowedTypes() {
//        return array();
//    }
 
    // override default accepts() method to allow nesting
    // - ie, to get the plugin accepts its own entry syntax
    function accepts($mode) {
        if ($mode == substr(get_class($this), 7)) return true;
        return parent::accepts($mode);
    }
    
    /**
     * What about paragraphs? (optional)
     */
    function getPType(){
        return 'block';
    }
 
    /**
     * Where to sort in?
     */ 
    function getSort(){
        return 601;
    }
 
 
    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
         $this->Lexer->addEntryPattern('<cli(?:[)]?' .
             '"(?:\\\\.|[^\\\\"])*"' .     /* double-quoted string */
             '|\'(?:\\\\.|[^\'\\\\])*\'' . /* single-quoted string */
             '|\\\\.' .                    /* escaped character */
             '|[^\'"\\\\>]|[(?:])*>\r?\n?(?=.*?</cli>)',$mode,'plugin_cli');
         /*
          * The [)]? and |[(?:] is to work around a bug in lexer.php
          * wrt nested (...)
         */
    }
 
    function postConnect() {
       $this->Lexer->addExitPattern('\r?\n?</cli>','plugin_cli');
    }
 
 
    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        switch ($state) {
          case DOKU_LEXER_ENTER :
            $args = substr($match, 4, -1);
            return array($state, $args);
          case DOKU_LEXER_MATCHED :
            break;
          case DOKU_LEXER_UNMATCHED :
            return array($state, $match);
          case DOKU_LEXER_EXIT :
            return array($state, '');
          case DOKU_LEXER_SPECIAL :
            break;
        }
        return array();
    }
 
    /**
     * Create output
     */
    function render($mode, &$renderer, $data) {
        if($mode == 'xhtml'){
             list($state, $match) = $data;
             switch ($state) {
             case DOKU_LEXER_ENTER :
                 $args = $match;
                 $this->_process_args($args);
                 $renderer->doc .= '<pre class="cli">';
                 break;
             case DOKU_LEXER_UNMATCHED : 
                 $this->_render_conversation($match, $renderer);
                 break;
             case DOKU_LEXER_EXIT :
                 $renderer->doc .= "</pre>";
                 break;
             }
             return true;
        }
        return false;
    }
 
     function _extract($args, $param) {
         /*
          * extracts value from $args for $param
          * xxx = "foo\"bar"  -> foo"bar
          * xxx = a\ b        -> a b
          * xxx = 'a\' b'     -> a' b
          *
          * returns null if value is empty.
          */
         if (preg_match("/$param" . '\s*=\s*(' .
             '"(?:\\\\.|[^\\\\"])*"' .     /* double-quoted string */
             '|\'(?:\\\\.|[^\'\\\\])*\'' . /* single-quoted string */
             '|(?:\\\\.|[^\\\\\s])*' .     /* escaped characters */
             ')/', $args, $matches)) {
             switch (substr($matches[1], 0, 1)) {
             case "'":
                 $result = substr($matches[1], 1, -1);
                 $result = preg_replace('/\\\\([\'\\\\])/', '$1', $result);
                 break;
             case '"':
                 $result = substr($matches[1], 1, -1);
                 $result = preg_replace('/\\\\(["\\\\])/', '$1', $result);
                 break;
             default:
                 $result = preg_replace('/\\\\(.)/', '$1', $matches[1]);
             }
             if ($result != "")
                 return $result;
         }
     }
 
     function _process_args($args) {
         // process args to CLI tag: sets $comment_str and $prompt_str
         if (!is_null($prompt = $this->_extract($args, 'prompt')))
             $this->prompt_str = $prompt;
         if (!is_null($comment = $this->_extract($args, 'comment')))
             $this->comment_str = $comment;
     }
    
    function _render_conversation($match, &$renderer) {
        $prompt_continues = false;
        $lines = preg_split('/\n\r|\n|\r/',$match);
        if ( trim($lines[0]) == "" ) unset( $lines[0] );
        if ( trim($lines[count($lines)]) == "" ) unset( $lines[count($lines)] );
        foreach ($lines as $line) {
//            $line = str_replace('root', 'utilisateur', $line);
            $line = str_replace('muffin', 'hostname', $line);
            $line = str_replace('192.168.17.0', '192.168.100.0', $line); // IP LAN
            $line = str_replace('192.168.17.254', '192.168.100.z', $line); // IP PASSERELLE
            $line = str_replace('192.168.17.10', '192.168.100.x', $line); // IP MUFFIN
            $line = str_replace('192.168.17.9', '192.168.100.y', $line); // IP MACARON
            $line = str_replace('192.168.17.11', '192.168.100.xyz', $line); // IP CHURROS ETH
            $line = str_replace('192.168.17.12', '192.168.100.xyz', $line); // IP CHURROS WIFI
//            $line = str_replace('failed', '<span class="wrap_alert">failed</span>', $line); // IP CHURROS WIFI
            $index = strpos($line, $this->prompt_str);
            if ($index === false) {   
                if ($this->prompt_continues) {
                  if (preg_match($this->prompt_cont, $line, $promptc) === 0) $this->prompt_continues = false;
                }
                if ($this->prompt_continues) {
                    // format prompt
                    $renderer->doc .= '<span class="cli_prompt">' . $renderer->_xmlEntities($promptc[0]) . "</span>";
                    // Split line into command + optional comment (only end-of-line comments supported)
                    $command =  preg_split($this->prompt_cont, $line);
                    $commands = explode($this->comment_str, $command[1]);
                    // Render command
                    $renderer->doc .= '<span class="cli_command">' . $renderer->_xmlEntities($commands[0]) . "</span>";
                    // Render comment if there is one
                    if ($commands[1]) {
                        $renderer->doc .= '<span class="cli_comment">' .
                            $renderer->_xmlEntities($this->comment_str . $commands[1]) . "</span>";
                  }
                  $renderer->doc .= DOKU_LF;
                } else {
                  // render as output
                  if ((strpos($line,'<wrap ') !== false) or (strpos($line,'<color ') !== false) or (strpos($line,'**') !== false) or (strpos($line,'//') !== false) or (strpos($line,'__') !== false) or (strpos($line,"''") !== false)) {
                    if (strpos($line,'<wrap ') !== false) {
                        $parts = explode('<wrap ', $line);
                        $line = "";
                        // before '<wrap '
                        $line = $line . $renderer->_xmlEntities($parts[0]);
                        $wrap =  explode('>', $parts[1]);
                        // $wrap[0] == em or hi or lo, $wrap[1] contains text to wrap followed by '</wrap' and if $wrap[2] exists, it contains the end of $line
                        $line = $line.'<span class="wrap_'.$wrap[0].'">';
                        $wrapped =  explode('</wrap', $wrap[1]);
                        $line = $line.$renderer->_xmlEntities($wrapped[0]).'</span>';
                        if ((isset($wrap[2])) and ($wrap[2] != "")) {
                            $line = $line.$renderer->_xmlEntities($wrap[2]);
                        }
                    }
                    if (strpos($line,'<color ') !== false) {
                      $parts = explode('<color ', $line);
                      $line = "";
                      // before '<color '
                      $line = $line . $renderer->_xmlEntities($parts[0]);
                      $color =  explode('>', $parts[1]);
                      // $color[0] is the desired color, $color[1] contains text to color followed by '</color' and if $color[2] exists, it contains the end of $line
                      $line = $line.'<span style="color:'.$color[0].';">';
                      $colored =  explode('</color', $color[1]);
                      $line = $line.$renderer->_xmlEntities($colored[0]).'</span>';
                      if ((isset($color[2])) and ($color[2] != "")) {
                          $line = $line.$renderer->_xmlEntities($color[2]);
                      }
                    }
                    if (strpos($line,'**') !== false) {
                      $parts = explode('**', $line);
                      if (count($parts) == 3) {
                          $line = $renderer->_xmlEntities($parts[0]).'<strong>'.$renderer->_xmlEntities($parts[1]).'</strong>'.$renderer->_xmlEntities($parts[2]);
                      }
                    }
                    if (strpos($line,'//') !== false) {
                      $parts = explode('//', $line);
                      if (count($parts) == 3) {
                          $line = $renderer->_xmlEntities($parts[0]).'<em>'.$renderer->_xmlEntities($parts[1]).'</em>'.$renderer->_xmlEntities($parts[2]);
                      }
                    }
                    if (strpos($line,'__') !== false) {
                      $parts = explode('__', $line);
                      if (count($parts) == 3) {
                          $line = $renderer->_xmlEntities($parts[0]).'<em class="u">'.$renderer->_xmlEntities($parts[1]).'</em>'.$renderer->_xmlEntities($parts[2]);
                      }
                    }
                    if (strpos($line,"''") !== false) {
                      $parts = explode("''", $line);
                      if (count($parts) == 3) {
                          $line = $renderer->_xmlEntities($parts[0]).'<code>'.$renderer->_xmlEntities($parts[1]).'</code>'.$renderer->_xmlEntities($parts[2]);
                      }
                    }
                  } else {
                      $line = $renderer->_xmlEntities($line);
                  }
//                      $line = str_replace('<wrap em>', '<span class="wrap_em">', $line);
//                      $line = str_replace('<wrap hi>', '<span class="wrap_hi">', $line);
//                      $line = str_replace('<wrap lo>', '<span class="wrap_lo">', $line);
//                      $line = str_replace('</wrap>', '</span>', $line);
//                  $renderer->doc .= '<span class="cli_output">' . $renderer->_xmlEntities($line) . "</span>" . DOKU_LF;
                    if ((strpos($line,"ERROR") !== false) or (strpos($line,"failed") !== false)) {
                        $detection = " wrap_alert";
                    } elseif ((strpos($line,"Possible missing") !== false) or (strpos($line,"[Warning]") !== false)) {
                        $detection = " wrap_important";
                    } elseif ((strpos($line,"Enabling") !== false) or (strpos($line,"[ ok ]") !== false)) {
                        $detection = " wrap_info";
                    } else {
                        $detection="";
                    }
                  $renderer->doc .= '<span class="cli_output'.$detection.'">' . $line . "</span>" . DOKU_LF;
                  $this->prompt_continues=false;
                }
            } else {
                $this->prompt_continues = true;
                // format prompt
                $prompt = substr($line, 0, $index) . $this->prompt_str;
                $renderer->doc .= '<span class="cli_prompt">' . $renderer->_xmlEntities($prompt) . "</span>";
                // Split line into command + optional comment (only end-of-line comments supported)
                $commands = explode($this->comment_str, substr($line, $index + strlen($this->prompt_str)));
                // Render command
                    $elements = explode("§", $commands[0]);
                    $commands[0] = "";
//var_dump($commands[0]);
//                    foreach ($elements as $element) {
                    for ($i = 0; $i < count($elements); $i++) {
//                        echo count($words);
//                        msg($word."?",0);
//                        if ((substr($word, 0, 1) === "[") and (substr($word, -1) == "]")) {
                        if ($i%2 == 1) {
                            $commands[0] = $commands[0].'<span class="cli_command wrap_tip" title="Variable à adapter">'.$renderer->_xmlEntities($elements[$i]).'</span>';
                        } else {
                            $commands[0] = $commands[0].$renderer->_xmlEntities($elements[$i]);
                        }
                    }
                 $renderer->doc .= '<span class="cli_command">' . $commands[0] . "</span>";
                // Render comment if there is one
                if ($commands[1]) {
                     $renderer->doc .= '<span class="cli_comment">' .
                        $renderer->_xmlEntities($this->comment_str . $commands[1]) . "</span>";
                }
                 $renderer->doc .= DOKU_LF;
            }
        }
    }
}
//Setup VIM: ex: et ts=4 enc=utf-8 sw=4 :
?>
