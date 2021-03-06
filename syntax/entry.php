<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     hh.lohmann <hh.lohmann@yahoo.de>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_data_entry extends DokuWiki_Syntax_Plugin {

    /**
     * @var helper_plugin_data will hold the data helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function syntax_plugin_data_entry(){
        $this->dthlp = plugin_load('helper', 'data');
        if(!$this->dthlp) msg('Loading the data helper failed. Make sure the data plugin is installed.',-1);
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *dataentry(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_data_entry');
    }

    /**
     * Handle the match - parse the data
     */
    function handle($match, $state, $pos, Doku_Handler &$handler){
        if(!$this->dthlp->ready()) return null;

        // get lines
        $lines = explode("\n",$match);
        array_pop($lines);
        $class = array_shift($lines);
        $class = str_replace('dataentry','',$class);
        $class = trim($class,'- ');

        // parse info
        $data = array();
        $columns = array();
        foreach ( $lines as $line ) {
            // ignore comments
            preg_match('/^(.*?(?<![&\\\\]))(?:#(.*))?$/',$line, $matches);
            $line = $matches[1];
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/',$line,2);

            $column = $this->dthlp->_column($line[0]);
            if (isset($matches[2])) $column['comment'] = $matches[2];
            if($column['multi']){
                if(!isset($data[$column['key']])) {
                    // init with empty array
                    // Note that multiple occurrences of the field are
                    // practically merged
                    $data[$column['key']] = array();
                }
                $vals = explode(',',$line[1]);
                foreach($vals as $val){
                    $val = trim($this->dthlp->_cleanData($val,$column['type']));
                    if($val == '') continue;
                    if(!in_array($val,$data[$column['key']])) $data[$column['key']][] = $val;
                }
            }else{
                $data[$column['key']] = $this->dthlp->_cleanData($line[1],$column['type']);
            }
            $columns[$column['key']]  = $column;
        }
        return array('data'=>$data, 'cols'=>$columns, 'classes'=>$class,
                     'pos' => $pos, 'len' => strlen($match)); // not utf8_strlen
    }

    /**
     * Create output or save the data
     */
    function render($format, Doku_Renderer &$renderer, $data) {
        if(is_null($data)) return false;
        if(!$this->dthlp->ready()) return false;

        global $ID;
        switch ($format){
            case 'xhtml':
                /** @var $renderer Doku_Renderer_xhtml */
                $this->_showData($data,$renderer);
                return true;
            case 'metadata':
                /** @var $renderer Doku_Renderer_metadata */
                $this->_saveData($data,$ID,$renderer->meta['title']);
                return true;
            case 'plugin_data_edit':
                /** @var $renderer Doku_Renderer_plugin_data_edit */
                $this->_editData($data, $renderer);
                return true;
            default:
                return false;
        }
    }

    /**
     * Output the data in a table
     *
     * @param array $data
     * @param Doku_Renderer_xhtml $R
     */
    function _showData($data, &$R){
        global $ID;
        $ret = '';

          // primitive "back to" link
        if ( $GLOBALS [ 'data-pf-backlink' ] !== false ) {
          $R->info['cache'] = false;
          $crumbs = array_reverse(breadcrumbs());
          $i = 0;
          foreach($crumbs as $id => $name) {
            if ( $i == 1 ) { break; }
            $i++;
          }
          $meta = p_get_metadata( $id );
          $ret .= '<p style=" margin: 1em; border: thin outset; padding: 0.5em; font-weight: bold; font-style: italic; ">'.html_wikilink(':'.$id,'⇐ zurück zu: '.$meta['title']).'</p>';
        }
          // (end of part)

        if (method_exists($R, 'startSectionEdit')) {
            $data['classes'] .= ' ' . $R->startSectionEdit($data['pos'], 'plugin_data');
        }
        $ret .= '<div class="inline dataplugin_entry '.$data['classes'].'"><table>';
        $class_names = array();
        foreach($data['data'] as $key => $val){
            if($val == '' || !count($val)) continue;
            $type = $data['cols'][$key]['type'];
            if (is_array($type)) $type = $type['type'];
            if ($type === 'hidden') continue;


            $class_name = hsc(sectionID($key, $class_names));
            $ret .= '<tr valign="top"><td class="' . $class_name . '">'.hsc($data['cols'][$key]['title']).'<span class="sep">: </span></td>';
            $ret .= '<td class="' . $class_name . '">';
            if(is_array($val)){
                $cnt = count($val);
                for ($i=0; $i<$cnt; $i++){
                    switch ($type) {
                        case 'pageid':
                            $type = 'title';
                        case 'wiki':
                            $val[$i] = $ID . '|' . $val[$i];
                            break;
                    }
                    $ret .= $this->dthlp->_formatData($data['cols'][$key], $val[$i],$R);
                    if($i < $cnt - 1) $ret .= '<span class="sep">, </span>';
                }
            }else{
                switch ($type) {
                    case 'pageid':
                        $type = 'title';
                    case 'wiki':
                        $val = $ID . '|' . $val;
                        break;
                }
                $ret .= $this->dthlp->_formatData($data['cols'][$key], $val, $R);
            }
            $ret .= '</td></tr>';
        }
        $ret .= '</table></div>';
        $R->doc .= $ret;
        if (method_exists($R, 'finishSectionEdit')) {
            $R->finishSectionEdit($data['len'] + $data['pos']);
        }
    }

    /**
     * Save date to the database
     */
    function _saveData($data,$id,$title){
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        if(!$title) $title = $id;

        $class = $data['classes'];

        // begin transaction
        $sqlite->query("BEGIN TRANSACTION");

        // store page info
        $this->replaceQuery("INSERT OR IGNORE INTO pages (page,title,class) VALUES (?,?,?)",
                            $id,$title,$class);

        // Update title if insert failed (record already saved before)
        $revision = filemtime(wikiFN($id));
        $this->replaceQuery("UPDATE pages SET title = ?, class = ?, lastmod = ? WHERE page = ?",
                            $title,$class,$revision,$id);

        // fetch page id
        $res = $this->replaceQuery("SELECT pid FROM pages WHERE page = ?",$id);
        $pid = (int) $sqlite->res2single($res);
        $sqlite->res_close($res);

        if(!$pid){
            msg("data plugin: failed saving data",-1);
            $sqlite->query("ROLLBACK TRANSACTION");
            return false;
        }

        // remove old data
        $sqlite->query("DELETE FROM DATA WHERE pid = ?",$pid);

        // insert new data
        foreach ($data['data'] as $key => $val){
            if(is_array($val)) foreach($val as $v){
                $this->replaceQuery("INSERT INTO DATA (pid, KEY, VALUE) VALUES (?, ?, ?)",
                                    $pid,$key,$v);
            }else {
                $this->replaceQuery("INSERT INTO DATA (pid, KEY, VALUE) VALUES (?, ?, ?)",
                                    $pid,$key,$val);
            }
        }

        // finish transaction
        $sqlite->query("COMMIT TRANSACTION");

        return true;
    }

    function replaceQuery() {
        $args = func_get_args();
        $argc = func_num_args();

        if ($argc > 1) {
            for ($i = 1; $i < $argc; $i++) {
                $data = array();
                $data['sql'] = $args[$i];
                $this->dthlp->_replacePlaceholdersInSQL($data);
                $args[$i] = $data['sql'];
            }
        }

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        return call_user_func_array(array(&$sqlite, 'query'), $args);
    }

    /**
     * The custom editor for editing data entries
     *
     * Gets called from action_plugin_data::_editform() where also the form member is attached
     *
     * @param array $data
     * @param Doku_Renderer_plugin_data_edit $renderer
     */
    function _editData($data, &$renderer) {
        $renderer->form->startFieldset($this->getLang('dataentry'));
        $renderer->form->_content[count($renderer->form->_content) - 1]['class'] = 'plugin__data';
        $renderer->form->addHidden('range', '0-0'); // Adora Belle bugfix

        if($this->getConf('edit_content_only')) {
            $renderer->form->addHidden('data_edit[classes]', $data['classes']);
            $renderer->form->addElement('<table>');
        } else {
            $renderer->form->addElement(form_makeField('text', 'data_edit[classes]', $data['classes'], $this->getLang('class'), 'data__classes'));
            $renderer->form->addElement('<table>');

            $text = '<tr>';
            foreach(array('title', 'type', 'multi', 'value', 'comment') as $val) {
                $text .= '<th>' . $this->getLang($val) . '</th>';
            }
            $renderer->form->addElement($text . '</tr>');

            // New line
            $data['data'][''] = '';
            $data['cols'][''] = array('type' => '', 'multi' => false);
        }

        $n = 0;
        foreach($data['cols'] as $key => $vals) {
            $fieldid = 'data_edit[data][' . $n++ . ']';
            $content = $vals['multi'] ? implode(', ', $data['data'][$key]) : $data['data'][$key];
            if(is_array($vals['type'])) {
                $vals['basetype'] = $vals['type']['type'];
                if(isset($vals['type']['enum'])) {
                    $vals['enum'] = $vals['type']['enum'];
                }
                $vals['type'] = $vals['origtype'];
            } else {
                $vals['basetype'] = $vals['type'];
            }

            if ($vals['type'] === 'hidden') {
                $renderer->form->addElement('<tr class="hidden">');
            } else {
                $renderer->form->addElement('<tr>');
            }
            if($this->getConf('edit_content_only')) {
                if(isset($vals['enum'])) {
                    $values = preg_split('/\s*,\s*/', $vals['enum']);
                    if(!$vals['multi']) array_unshift($values, '');
                    $content = form_makeListboxField(
                        $fieldid . '[value][]', $values,
                        $data['data'][$key], $vals['title'], '', '', ($vals['multi'] ? array('multiple' => 'multiple') : array())
                    );
                } else {
                    $classes = 'data_type_' . $vals['type'] . ($vals['multi'] ? 's' : '') . ' ' .
                        'data_type_' . $vals['basetype'] . ($vals['multi'] ? 's' : '');

                    $attr = array();
                    if($vals['basetype'] == 'date' && !$vals['multi']) {
                        $attr['class'] = 'datepicker';
                    }

                    $content = form_makeField('text', $fieldid . '[value]', $content, $vals['title'], '', $classes, $attr);

                }
                $cells = array(
                    $vals['title'] . ':',
                    $content,
                    $vals['comment']
                );
                foreach(array('multi', 'comment', 'type') as $field) {
                    $renderer->form->addHidden($fieldid . "[$field]", $vals[$field]);
                }
                $renderer->form->addHidden($fieldid . "[title]", $vals['origkey']); //keep key as key, even if title is translated
            } else {
                $check_data = $vals['multi'] ? array('checked' => 'checked') : array();
                $cells = array(
                    form_makeField('text', $fieldid . '[title]', $vals['origkey'], $this->getLang('title')), // when editable, alsways use the pure key, not a title
                    form_makeMenuField(
                        $fieldid . '[type]',
                        array_merge(
                            array(
                                 '', 'page', 'nspage', 'title',
                                 'img', 'mail', 'url', 'tag', 'wiki', 'dt', 'hidden'
                            ),
                            array_keys($this->dthlp->_aliases())
                        ),
                        $vals['type'],
                        $this->getLang('type')
                    ),
                    form_makeCheckboxField($fieldid . '[multi]', array('1', ''), $this->getLang('multi'), '', '', $check_data),
                    form_makeField('text', $fieldid . '[value]', $content, $this->getLang('value')),
                    form_makeField('text', $fieldid . '[comment]', $vals['comment'], $this->getLang('comment'), '', 'data_comment', array('readonly' => 1))
                );
            }
            foreach($cells as $cell) {
                $renderer->form->addElement('<td>');
                $renderer->form->addElement($cell);
                $renderer->form->addElement('</td>');
            }
            $renderer->form->addElement('</tr>');
        }

        $renderer->form->addElement('</table>');
        $renderer->form->endFieldset();
    }

    /**
     * Escapes the given value against being handled as comment
     *
     * @todo bad naming
     * @param $txt
     * @return mixed
     */
    public static function _normalize($txt) {
        return str_replace('#', '\#', trim($txt));
    }

    /**
     * Handles the data posted from the editor to recreate the entry syntax
     *
     * @param array $data data given via POST
     * @return string
     */
    public static function editToWiki($data) {
        $nudata = array();

        $len = 0; // we check the maximum lenght for nice alignment later
        foreach ($data['data'] as $field) {
            if (is_array($field['value'])){
                $field['value'] = join(', ', $field['value']);
            }
            $field = array_map('trim', $field);
            if ($field['title'] === '') continue;

            $name = syntax_plugin_data_entry::_normalize($field['title']);

            if($field['type'] !== '') {
                $name .= '_' . syntax_plugin_data_entry::_normalize($field['type']);
            }elseif(substr($name,-1,1) === 's'){
                $name .= '_'; // when the field name ends in 's' we need to secure it against being assumed as multi
            }
            if ($field['multi'] === '1') $name .= 's'; // 's' is added to either type or name for multi

            $nudata[] = array($name, syntax_plugin_data_entry::_normalize($field['value']),
                              $field['comment']);
            $len = max($len, utf8_strlen($nudata[count($nudata) - 1][0]));
        }

        $ret = '---- dataentry ' . trim($data['classes']) . ' ----' . DOKU_LF;
        foreach ($nudata as $field) {
            $ret .= $field[0] . str_repeat(' ', $len + 1 - utf8_strlen($field[0])) . ': ' .
                    $field[1];
            if ($field[2] !== '') {
                $ret .= ' # ' . $field[2];
            }
            $ret .= DOKU_LF;
        }
        $ret .= "----\n";
        return $ret;
    }
}
