<?php
/**
 *  PHP Git
 *
 *  Pure-PHP class to read GIT repositories. It allows 
 *
 *
 *  PHP version 5
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   C�sar D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */

define("OBJ_COMMIT", 1);
define("OBJ_TREE", 2);
define("OBJ_BLOB", 3);
define("OBJ_TAG", 4);
define("OBJ_OFS_DELTA", 6);
define("OBJ_REF_DELTA", 7);
define("GIT_INVALID_INDEX", 0x02);
define("PACK_IDX_SIGNATURE", "\377tOc");

/**
 *  Git Base Class
 *
 *  This class provide a set of fundamentals functions to
 *  manipulate (read only for now) a git repository.
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   C�sar D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
abstract class GitBase
{
    private $_dir = false;
    private $_cache_obj;
    private $_index = array();
    protected $branch;
    protected $refs;

    // {{{ throwException
    /**
     *  Throw Exception 
     *
     *  This is the only function that throws an Exepction,
     *  used to easy portability to PHP4.
     *
     *  @param string $str Description of the exception
     *
     *  @return class Exception
     */
    final protected function throwException($str)
    {
        throw new Exception ($str);
    }
    // }}}

    // {{{ getFileContents
    /**
     *  Get File contents
     *
     *  This function reads a file and returns its content, 
     *
     *  @param string $path     File path
     *  @param bool   $relative If true, it appends the .git directory
     *  @param bool   $raw      If true, returns as is, otherwise return trimmed
     *
     *  @return mixed  File contents or false if fails. 
     */
    final protected function getFileContents($path, $relative=true, $raw=false)
    {
        if ( $relative ) {
            $path = $this->_dir."/".$path;
        }
        if (!is_file($path)) {
            return false;
        }
        return $raw ? file_get_contents($path) :  trim(file_get_contents($path));
    }
    // }}}

    // {{{ setRepo 
    /** 
     *  set Repository
     *
     *  @param string $dir Directory path
     *
     *  @return mixed True if sucess otherwise an Exception
     */
    final function setRepo($dir)
    {
        if (!is_dir($dir)) {
            $this->throwException("$dir is not a valid dir");
        }
        $this->_dir   = $dir; 
        $this->branch = null;
        if (($head=$this->getFileContents("HEAD")) === false) {
            $this->_dir = false;
            $this->throwException("Invalid repository, there is not HEAD file");
        }
        if (!$this->_loadBranchesInfo()) {
            $this->_dir = false;
            $this->throwException("Imposible to load information about branches");
        }
        return true;
    }
    // }}}

    // {{{ _loadBranchesInfo
    /**
     *  Load Branches Info
     *
     *  This function loads information about the avaliable
     *  branches in the actual repository.
     *
     *  @return boolean True is success, otherwise false.
     */
    final private function _loadBranchesInfo()
    {
        $branch = & $this->branch;
        $files  = glob($this->_dir."/refs/heads/*");
        if (count($files) === 0) {
            $file       = $this->getFileContents("packed-refs");
            $this->refs = $this->simpleParsing($file, -1, ' ', false);
            foreach ($this->refs as $ref=>$sha1) {
                if (strpos($ref, "refs/heads") === 0) {
                    $id            = substr($ref, strrpos($ref, "/")+1);
                    $branch[ $id ] = $sha1;
                }
            }
            return count($branch) != 0;
        }
        foreach ($files as $file) {
            $id            = substr($file, strrpos($file, "/")+1);
            $branch[ $id ] = $this->getFileContents($file, false);
        }
        return true;
    }
    // }}} 

    // {{{ getObject
    /** 
     *  Get Object
     *
     *  This function is main function of the class, it receive
     *  an object ID (sha1) and returns its content. The object
     *  could be store in "loose" format or packed.
     *
     *  @param string $id SHA1 Object ID.
     *
     *  @return mixed Object's contents or false.
     */
    final function getObject($id)
    {
        if (isset($this->_cache_obj[$id])) {
            return $this->_cache_obj[$id];
        }
        $name = substr($id, 0, 2)."/".substr($id, 2);
        if (($content = $this->getFileContents("objects/$name")) !== false) {
            /* the object is in loose format, less work for us */
            return $this->_cache_obj[$id] = $gzinflate(substr($content, 2));
        } else {
            $obj = $this->_getPackedObject($id);
            if ($obj !== false) {
                return $this->_cache_obj[$id] = $obj[1];
            }
        }
        return false;
    }
    // }}} 

    // {{{ sha1ToHex
    /**
     *  Transform a raw sha1 (20bytes) into it's hex representation
     *
     *  @param string $sha1 Raw sha1
     *
     *  @return string Hex sha1
     */
    final protected function sha1ToHex($sha1)
    {
        $str = "";
        for ($i=0; $i < 20; $i++) {
            $hex = dechex(ord($sha1[$i]));
            if (strlen($hex)==1) {
                $hex = "0".$hex;
            }
            $str .= $hex;
        }
        return $str;
    }
    // }}}

    // {{{ getNumber
    /** 
     *  Transform 4bytes into a bigendian number.
     *
     *  @param string $bytes 4 bytes.
     *  
     *  @return int
     */
    final public function getNumber($bytes)
    {
        $c = unpack("N", $bytes);
        return $c[1];
    }
    // }}}

    // {{{ _getIndexInfo
    /**
     *  Loads the pack index file, and parse it.
     *
     *  @param string $path Index file path
     *
     *  @return mixed Index structure (array) or an exception
     */
    final private function _getIndexInfo($path)
    {
        if (isset($this->_index[$path])) {
            return $this->_index[$path];
        }
        $content = $this->getFileContents($path, false, true);
        $version = 1;
        $hoffset = 0;
        if (substr($content, 0, 4) == PACK_IDX_SIGNATURE) {
            $version = $this->getNumber(substr($content, 4, 4));
            if ($version != 2) {
                $this->throwException("The pack-id's version is $version, PHPGit
                        only supports version 1 or 2,please update this 
                        package, or downgrade your git repo");
            }
            $hoffset = 8;
        }
        $indexes = unpack("N*", substr($content, $hoffset, 256*4));
        $nr      = 0;
        for ($i=0; $i < 256; $i++) {
            if (!isset($indexes[$i+1])) {
                continue;
            }
            $n =  $indexes[$i+1];
            if ($n < $nr) {
                $this->throwException("corrupt index file ($n, $nr)\n");
            }
            $nr = $n;
        }   
        $_offset = $hoffset + 256 * 4;
        if ($version == 1) {
            $offset = $_offset;
            for ($i=0; $i < $nr; $i++) {
                $field     = substr($content, $offset, 24);
                $id        = unpack("N", $field);
                $key       = $this->sha1ToHex(substr($field, 4));
                $tmp[$key] = $id[1];
                $offset   += 24;
            }
            $this->_index[$path] = $tmp;
        } else if ($version == 2) {
            $offset = $_offset;
            $keys   = $data = array();
            for ($i=0; $i < $nr;  $i++) {
                $keys[]  = $this->sha1ToHex(substr($content, $offset, 20));
                $offset += 20;
            } 
            for ($i=0; $i < $nr; $i++) {
                $offset += 4;
            }
            for ($i=0; $i < $nr; $i++) {
                $data[]  = $this->getNumber(substr($content, $offset, 4));
                $offset += 4;
            }
            $this->_index[$path] = array_combine($keys, $data);
        }
        return $this->_index[$path];
    }
    // }}}

    // {{{ _getPackedObject
    /**
     *  Get an object from the pack.
     *
     *  @param string $id    sha1 (40bytes). object's id.
     *  @param int    &$type By-reference variable which contains the object's type.
     *  
     *  @return mixed Objects content or false otherwise.
     */
    final private function _getPackedObject($id, &$type=null)
    {
        /* load packages */
        foreach (glob($this->_dir."/objects/pack/*.idx") as $findex) {
            $index = $this->_getIndexInfo($findex);
            if (isset($index[$id])) {
                $start = $index[$id];
                /* open pack file */
                $pack_file = substr($findex, 0, strlen($findex)-3)."pack";
                $fp        = fopen($pack_file, "rb");

                $object =  $this->_unpackObject($fp, $start);

                fclose($fp);

                return $object;
            }
        }
        return false;
    }
    // }}}

    // {{{ _unpackObject 
    /**
     *  Unpack an file from the start bytes.
     *
     *  @param resource $fp    Filepointer.
     *  @param int      $start The object start position.
     *
     *  @return mixed Array with type and content or an exception
     */
    final private function _unpackObject($fp, $start)
    {
        /* offset till the start of the object */
        fseek($fp, $start, SEEK_SET);
        /* read first byte, and get info */
        $header  = ord(fread($fp, 1));
        $type    = ($header >> 4) & 7;
        $hasnext = ($header & 128) >> 7; 
        $size    = $header & 0xf;
        $offset  = 4;
        /* read size bytes */
        while ($hasnext) {
            $byte = ord(fread($fp, 1)); 
            $size   |= ($byte & 0x7f) << $offset; 
            $hasnext = ($byte & 128) >> 7; 
            $offset +=7;
        }

        switch ($type) {
        case OBJ_COMMIT:
        case OBJ_TREE:
        case OBJ_BLOB:
        case OBJ_TAG:
            $obj = $this->_unpackCompressed($fp, $size);
            return array($type, $obj);
            break;
        case OBJ_OFS_DELTA:
        case OBJ_REF_DELTA:
            $obj = $this->_unpackDelta($fp, $start, $type, $size);
            return array($type, $obj);
            break;
        default:
            $this->throwException("Unkown object type $type");
        }
    }
    // }}}

    // {{{ _unpackCompressed
    /** 
     *  Unpack a compressed object
     *
     *  @param resource $fp   Filepointer
     *  @param int      $size Object's start position.
     *
     *  @return mixed Object's content or an Exception
     */
    final private function _unpackCompressed($fp, $size)
    {
        fseek($fp, 2, SEEK_CUR);
        $out ="";
        do {
            $cstr         = fread($fp, 4096);
            $uncompressed = gzinflate($cstr);
            if ($uncompressed === false) {
                $this->throwException("fatal error while uncompressing");
            }
            $out .= $uncompressed; 
        } while (strlen($out) < $size);
        if ($size != strlen($out)) {
            $this->throwException("Weird error, the packed object has invalid size");
        }
        return $out;
    }
    // }}}

    // {{{ _unpackDelta
    /** 
     *  Unpack a delta file, and it's other objects and apply the patch.
     *
     *  @param resource $fp        Filepointer
     *  @param int      $obj_start Delta start position.
     *  @param int      $type      Delta type.
     *  @param int      $size      Delta size.
     *  
     *  @return mixed Object's content or an Exception
     */
    final private function _unpackDelta($fp, $obj_start, $type, $size)
    {
        $delta_offset = ftell($fp);
        $sha1         = fread($fp, 20);
        if ($type == OBJ_OFS_DELTA) {
            $i      = 0;
            $c      = ord($sha1[$i]);
            $offset = $c & 0x7f;
            while (($c & 0x80) != 0) {
                $c       = ord($sha1[ ++$i ]);
                $offset += 1;
                $offset <<= 7;
                $offset |= $c & 0x7f;
            }
            $offset = $obj_start - $offset;
            $i++;
            /* unpack object */
            list($type, $base) = $this->_unpackObject($fp, $offset);
        } else {
            $base = $this->_getPackedObject($sha1);
            $i    = 20;
        }
        /* get compressed delta */
        fseek($fp, $delta_offset+$i, SEEK_SET);
        $delta = $this->_unpackCompressed($fp, $size); 

        /* patch the base with the delta */
        $obj = $this->patchObject($base, $delta);

        return $obj;
    }
    // }}}

    // {{{ patchDeltaHeaderSize
    /**
     *  Returns the delta's content size.
     *
     *  @param string &$delta Delta contents.
     *  @param int    $pos    Delta offset position.
     *
     *  @return mixed Delta size and position or an Exception.
     */
    final protected function patchDeltaHeaderSize(&$delta, $pos)
    {
        $size = $shift = 0;
        do {
            $byte = ord($delta[$pos++]);
            if ($byte == null) {
                $this->throwException("Unexpected delta's end.");
            }
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) != 0);
        return array($size, $pos);
    }
    // }}}

    // {{{ patchObject
    /**
     *  Apply a $base to a $delta
     *
     *  @param string &$base  String to apply to the delta.
     *  @param string &$delta Delta content.
     *
     *  @return mixed Objects content or an Exception
     */
    final protected function patchObject(&$base, &$delta)
    {
        list($src_size, $pos) = $this->patchDeltaHeaderSize($delta, 0);
        if ($src_size != strlen($base)) {
            $this->throwException("Invalid delta data size");
        }
        list($dst_size, $pos) = $this->patchDeltaHeaderSize($delta, $pos);

        $dest       = "";
        $delta_size = strlen($delta);
        while ($pos < $delta_size) {
            $byte = ord($delta[$pos++]);
            if ( ($byte&0x80) != 0 ) {
                $pos--;
                $cp_off = $cp_size = 0;
                /* fetch start position */
                $flags = array(0x01, 0x02, 0x04, 0x08);
                for ($i=0; $i < 4; $i++) {
                    if ( ($byte & $flags[$i]) != 0) {
                        $cp_off |= ord($delta[++$pos]) << ($i * 8);
                    }
                }
                /* fetch length  */
                $flags = array(0x10, 0x20, 0x40);
                for ($i=0; $i < 3; $i++) {
                    if ( ($byte & $flags[$i]) != 0) {
                        $cp_size |= ord($delta[++$pos]) << ($i * 8);
                    }
                }
                /* default length */
                if ($cp_size === 0) {
                    $cp_size = 0x10000;
                }
                $part = substr($base, $cp_off, $cp_size);
                if (strlen($part) != $cp_size) {
                    $this->throwException("Patching error: expecting $cp_size 
                            bytes but only got ".strlen($part));
                }
                $pos++;
            } else if ($byte != 0) {
                $part = substr($delta, $pos, $byte);
                if (strlen($part) != $byte) {
                    $this->throwException("Patching error: expecting $byte
                            bytes but only got ".strlen($part));
                } 
                $pos += $byte;
            } else {
                $this->throwException("Invalid delta data at position $pos");
            }
            $dest .= $part;
        }
        if (strlen($dest) != $dst_size) {
            $this->throwException("Patching error: Expected size and patched
                    size missmatch");
        }
        return $dest;
    }
    /* }}} */

    // {{{ simpleParsing
    /**
     *  Simple parsing
     *
     *  This function implements a simple parsing for configurations
     *  and description files from git.
     *
     *  @param string $text   string to parse
     *  @param int    $limit  lines to proccess.
     *  @param string $sep    separator string.
     *  @param bool   $findex If true the first column is the key if not is the data.
     *  
     *  @return Array 
     */
    final protected function simpleParsing($text, $limit=-1, $sep=' ', $findex=true)
    {
        $return = array();
        $i      = 0;
        foreach (explode("\n", $text) as $line) {
            if ($limit != -1 && $limit < ++$i ) {
                break; 
            }
            $info = explode($sep, $line, 2);
            if (count($info) != 2) {
                break;
            }
            list($first, $second) = $info; 

            $key          = $findex ? $first : $second;
            $return[$key] = $findex ? $second : $first;
        }
        return $return;
    }
    // }}}

}

/**
 *  Git Class
 *
 *  @category VersionControl
 *  @package  PHP-Git
 *  @author   C�sar D. Rodas <crodas@member.fsf.org>
 *  @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 *  @link     http://cesar.la/git
 */
class Git extends GitBase
{
    private $_cache;

    // {{{ __construct 
    /**
     *  Class constructor
     *
     *  @param string $path Git path repo.
     *
     *  @return null
     */
    function __construct($path='')
    {
        if ($path=='') {
            return;
        }
        $this->setRepo($path);
    }
    // }}}

    // {{{ getBranches
    /**
     *  Returns all the branches
     *
     *  @return Array list of branches
     */
    function getBranches()
    {
        return array_keys($this->branch);
    }
    // }}}

    // {{{ getHistory
    /**
     *  Returns all commit history on a given branch.
     *
     *  @param string $branch Branch name
     *
     *  @return mixed Array with commits history or exception
     */
    function getHistory($branch)
    {
        if (isset($this->_cache['branch'])) {
            return $this->_cache['branch'];
        }
        if (!isset($this->branch[$branch])) {
            $this->throwException("$branch is not a valid branch");
        }
        $object_id = $this->branch[$branch];

        $history = array();
        do { 
            $object_text         = $this->getObject($object_id);
            $commit              = $this->simpleParsing($object_text, 4);
            $commit['comment']   = trim(strstr($object_text, "\n\n")); 
            $history[$object_id] = $commit;

            $object_id = isset($commit['parent']) ? $commit['parent'] : false;
        } while (strlen($object_id) > 0);
        return $this->_cache['branch'] = $history;
    }    
    // }}} 

    // {{{ getCommit
    /**
     *  Get commit list of files.
     *
     *  @param string $id Commit Id.
     *
     *  @return mixed Array with commit's files or an exception
     */
    function getCommit($id)
    {
        $found = false;
        foreach ($this->getBranches() as $branch) {
            $commits = $this->getHistory($branch);
            foreach ($commits as $commit) {
                if (isset($commit['tree']) && $commit['tree'] == $id) {
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            $this->throwException("$id is not a valid commit");
        }

        $data     = $this->getObject($id);
        $data_len = strlen($data);
        $i        = 0;
        $return   = array();
        while ($i < $data_len) {
            $pos = strpos($data, "\0", $i);

            list($mode, $name) = explode(' ', substr($data, $i, $pos-$i), 2);

            $mode         = intval($mode, 8);
            $node         = new stdClass;
            $node->id     = $this->sha1ToHex(substr($data, $pos+1, 20));
            $node->name   = $name;
            $node->is_dir = !!($mode & 040000); 
            $return[]     = $node;
            $i            = $pos + 21;
        }
        return $return;
    }
    // }}}

}

$repo = new Git("/home/crodas/projects/playground/phpserver/phplibtextcat/.git");
//$repo = new Git("/home/crodas/projects/bigfs/.git");
var_dump($repo->getBranches());
$history = $repo->getHistory('master');
var_dump($history);
$commit = $repo->getCommit('b22d9c85cd28af4a4c8059614521cb42d94ade49');
//$commit = $repo->getCommit('fb12298bd8eac7f368d435b1256047d09d4773ef');
var_dump($commit);

$object = $repo->getObject('d7ca87cc92e7007b831f449e0afd9ff92c33dc83');
var_dump($object);

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
?>
