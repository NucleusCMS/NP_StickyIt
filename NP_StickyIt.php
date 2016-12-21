<?php
// plugin needs to work on Nucleus versions <=2.0 as well
if (!function_exists('sql_table')){
	function sql_table($name) {
		return 'nucleus_' . $name;
	}
}

define ("_DEBUG_MODE", false);

class NP_StickyIt extends NucleusPlugin {

	function NP_StickyIt(){// 'd' = default
		$this->d_rank = 10;
		$this->maxrank = 50;
		$this->d_task = 1; // 0:Un-displaying 1:Shifts to an ordinary item
		$this->editlink = 1; // insert a link to the edit page automatically.
		$this->dateheads = 0; // show date title
		$this->name = 'StickyIt';
	}

	function getName() { return 'Sticky It'; } 
	function getAuthor() { return 'Taka'; } 
	function getURL() { return 'http://vivian.stripper.jp/'; } 
	function getVersion() { return '0.21'; } 
	function getDescription() { 
		return 'Sticky It.'; 
	} 

	function getTableList() {	return array(sql_table('plug_stickyit'),sql_table('plug_stickyit_group')); }
	function getEventList() {	return array('PostAddItem','PreUpdateItem','AddItemFormExtras','EditItemFormExtras','PostDeleteItem','PreSkinParse'); }

	function supportsFeature($what) {
		switch($what){
			case 'SqlTablePrefix':
				return 1;
			default:
				return 0;
		}
	}

	function install() {
		sql_query('CREATE TABLE IF NOT EXISTS ' . sql_table('plug_stickyit'). ' (
			snumber int(11) not null, 
			spost datetime,
			sstart datetime, 
			slimit datetime, 
			stask int(2) not null,
			snewcat int(11) not null,
			sgroup int(11) not null, 
			srank tinyint not null default '.$this->d_rank.', 
			PRIMARY KEY (snumber))');

		sql_query("CREATE TABLE IF NOT EXISTS " . sql_table('plug_stickyit_group'). " (
			sgroupid int(11) not null auto_increment, 
			sgname varchar(128) not null default '',
			PRIMARY KEY (sgroupid))");
		
		$check_rows = sql_query('SELECT * FROM '. sql_table('plug_stickyit_group'));
		if (mysql_num_rows($check_rows) < 1) {
			$query = "INSERT INTO ". sql_table('plug_stickyit_group') ." SET sgname='default'";
			sql_query($query);
		}

		$this->createOption('sort', 'デフォルトの並び順(ASC = 昇順 / DESC = 降順)', 'text', 'DESC');
		$this->createOption('del_uninstall', 'アンインストール時データテーブルも削除する', 'yesno', 'no');
	}

	function unInstall() {
		if ($this->getOption('del_uninstall') == "yes") {
			sql_query("DROP table ".sql_table('plug_stickyit'));
			sql_query("DROP table ".sql_table('plug_stickyit_group'));
		}
	}

/**
  * Option setting form is displayed on "Add/Edit Item Form".
	*
  */

	function event_AddItemFormExtras($data) {
		$stickymode = 0;
		$start = getdate($data['blog']->getCorrectTime());
		$group = 0;
		$rank = $this->d_rank;
		
		$this->showOptionForm($stickymode,$group,$rank);
	}
	
	function event_EditItemFormExtras($data) {
		$stickyid = intval($data['itemid']);
		$res = sql_query('SELECT sgroup, srank FROM '.sql_table('plug_stickyit').' WHERE snumber='.$stickyid);
		
		if (mysql_num_rows($res) > 0) {
		
			while ($obj = mysql_fetch_object($res)) {
				$stickymode = 1;
				$group = $obj->sgroup;
				$rank = $obj->srank;
				$draft = $data['variables']['draft'];
			}
		
		} else {
			$stickymode = 0;
			$group = 0;
			$rank = $this->d_rank;
			$draft = $data['variables']['draft'];
		}
		
		$edit = 1;
		$this->showOptionForm($stickymode,$group,$rank,$edit,$draft);
	}

	function showOptionForm($stickymode,$group,$rank,$edit=0,$draft=0) {
?>
<!--			<h3>StickyIt</h3> -->
			<h3>申し送り</h3>

<!--				<label for="plug_stickyit"><strong>Sticky it!</strong> </label>  -->
				<label for="plug_stickyit"><strong>申し送りに追加</strong> </label>
				<input type="checkbox" value="1" id="plug_stickyit" name="sticky_it"<?php if($stickymode) echo ' checked="checked"'?> />
				| GROUP
<?php

		echo $this->showGroupForm($group);
		echo "\n".'RANK'."\n";
		echo $this->showRankForm($this->d_rank);
		
		if($edit && !$draft) {
			echo "\n".'| <label for="plug_sticky_todraft">ドラフト保存に変更</label> <input type="checkbox" name="sticky_todraft" id="plug_sticky_todraft" value="1" /><br /><br />'."\n";
		}

	}


/**
  * Add/Update/Release a sticky item.
	*
  */

	/**
	  * EVENT HANDLER
	  */
	function event_PostAddItem($data) {
		switch (intRequestVar('sticky_it')) {
			case 1:
				$stickyid = $data['itemid'];
				$res = $this->manageStickyItem($stickyid,'additem');
				if ($res) {
					ACTIONLOG::add(WARNING, 'NP_StickyIt Error:'.$res.' (ItemID'.$stickyid.')');
				}
				break;
		}
	}

	function event_PreUpdateItem($data) {
		$stickyid = $data['itemid'];
		$posttime = '';
		$itemtime = '';
		
		if (intRequestVar('sticky_todraft')) {
			// changes the item into the draft item.
			$posttime = quickQuery('SELECT itime as result FROM '.sql_table('item').' WHERE inumber='.$stickyid);
			$r = mysql_query('UPDATE '.sql_table('item').' SET'
			.' itime=' .mysqldate(0).', idraft=1'
			.' WHERE inumber='.$stickyid);
			if(!$r) {
				ACTIONLOG::add(WARNING, 'NP_StickyIt Error:Has not changed into draft. (ItemID'.$stickyid.')');
				return;
			}
		}
		
		$stickymode = intRequestVar('sticky_it');// add or continue or release ?
		// check whether the item exists in the 'stickyit' table.
		$num = quickQuery('SELECT COUNT(snumber) as result FROM '.sql_table('plug_stickyit').' WHERE snumber='.$stickyid);
		if ($num > 0) {
			if ($stickymode < 1) { // release
				$res = $this->delStickyItem($stickyid,'releaseitem',$posttime);
			} else { // continue (other settings may be updated)
				$res = $this->manageStickyItem($stickyid,'updateitem',$posttime);
			}
		} elseif($stickymode >= 1) { // add
			$res = $this->manageStickyItem($stickyid,'updateadditem',$posttime);
		}
		if ($res) {
			ACTIONLOG::add(WARNING, 'NP_StickyIt Error:'.$res.' (ItemID'.$stickyid.')');
		}
	}
	
	function event_PostDeleteItem($data) {
		$stickyid = $data['itemid'];
		$res = $this->delStickyItem($stickyid,'deleteorignal');
		if ($res) {
			ACTIONLOG::add(WARNING, 'NP_StickyIt Error:'.$res.' (ItemID'.$stickyid.')');
		}
	}
	
	function event_PreSkinParse($data) {
		global $blog, $manager;
		switch ($data['type']) {
			case index:
				if ($blog) {
					$b =& $blog; 
				} else {
					$b =& $manager->getBlog($CONF['DefaultBlog']);
				}
				// check
				$query = 'SELECT snumber FROM '.sql_table(plug_stickyit).' WHERE slimit<'.mysqldate($b->getCorrectTime());
				$res = mysql_query($query);
				if ($res) {
					while ($o = mysql_fetch_object($res)) {
						$error = $this->delStickyItem($o->snumber,'');
						if ($error) {
							ACTIONLOG::add(WARNING, 'NP_StickyIt Error:failed deletion of data.');
							break;
						}
					}
				} else {
					ACTIONLOG::add(WARNING, 'NP_StickyIt Error:line:'.__LINE__.'[mySQL]'.mysql_error());
				}
				break;
		}
	}

	/**
		* Actually update table. This function is called from 
		*
		* "event_PostAddItem"($mode='additem'),
		* "event_PreUpdateItem"($mode='updateadditem','updateitem'),
		* "doAction"($mode='addlistitem','updatelistitem').
		*
		* If it fails, this will return an error message to these functions.
	  */
	function manageStickyItem($stickyid,$mode,$posttime=''){
		global $CONF, $manager;
		
		$b =& $manager->getBlog(getBlogIDFromItemID($stickyid));
		$item =& $manager->getItem($stickyid,1,0);
		if (!$item) return '[Add/Update]Unexisting item.';
		
		$itemdraft = $item['draft']; 
		$group = intRequestVar('sticky_group');
		$rank = intRequestVar('sticky_rank');
		$nowtime = $b->getCorrectTime();
		if ($posttime) $posttime = '"'.$posttime.'"';
		
		switch ($mode) {
			case 'additem':
				// This is necessary to set up "task."
				if ($itemdraft) { // the draft item
					$posttime = mysqldate($nowtime);
					$task = 0;
				} else { // the ordinary item
					$task = 1;
				}
				$start = mysqldate($nowtime);
				
				$query = 'INSERT INTO ' . sql_table('plug_stickyit')
				 . ' (snumber, sstart';
				if ($posttime) $query .=', spost';
				$query .= ', stask, sgroup, srank) VALUES ('.$stickyid.', '.$start;
				if(isset($posttime)) $query .= ', '.$posttime;
				$query .= ', '.$task.', '.$group.', '.$rank.')';
				
$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

				$res = mysql_query($query);
				if(!$res) {
					return 'line:'.__LINE__.'[mySQL]'.mysql_error();
				}
				break;
			
			case 'updateadditem':
				// This is necessary to set up "task."
				
				/* has been in draft  == $itemdraft
				   has been in draft || the ordinary item == !$posttime
				   changes now == $posttime && !$itemdraft */
				
				if ($posttime || $itemdraft) { // the draft item
					$task = 0;
				} else { // the ordinary item
					$task = 1;
				}
				if ($itemdraft && !$posttime) { // has been in draft && add Sticky
					$posttime = mysqldate($nowtime);
				}
				
				$start = mysqldate($nowtime);
				$query = 'INSERT INTO ' . sql_table('plug_stickyit')
				 . ' (snumber, sstart';
				if ($posttime) $query .=', spost';
				$query .= ', stask, sgroup, srank) VALUES ('.$stickyid.', '.$start;
				if($posttime) $query .= ', '.$posttime;
				$query .= ', '.$task.', '.$group.', '.$rank.')';
				
$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

				$res = mysql_query($query);
				if(!$res) {
					return 'line:'.__LINE__.'[mySQL]'.mysql_error();
				}
				break;
				
			case 'updateitem':
				if ($posttime) {
					$task = 0;
				}
					$query = 'UPDATE ' . sql_table('plug_stickyit')
					. ' SET sgroup=' .$group.', srank=' .$rank;
					if ($posttime) $query .= ', spost=' .$posttime.', stask=' .$task;
					$query .= ' WHERE snumber=' .$stickyid;
				
$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

					$res = mysql_query($query);
					if(!$res) {
						return 'line:'.__LINE__.'[mySQL]'.mysql_error();
					}
				break;
		}
		
		switch ($mode) {
			case 'addlistitem':
			case 'updatelistitem':
				switch (intRequestVar('sticky_task')) {
					case 0:
						$task = 0;
						$newcat = 0;
						break;
					default:
						$task = 1;
						$newcat = intRequestVar('sticky_cat');
				}
				$start = mktime(requestVar('sticky_shour'), requestVar('sticky_sminutes'), 0, requestVar('sticky_smonth'), requestVar('sticky_sday'), requestVar('sticky_syear'));
				$start = @mysqldate($start);
				if (!$start) {
					$start = mysqldate($nowtime);
					ACTIONLOG::add(WARNING, 'NP_StickyIt Error:Start time is wrong. (ItemID'.$stickyid.')');
				}

				$lm = mktime(requestVar('sticky_lhour'), requestVar('sticky_lminutes'), 0, requestVar('sticky_lmonth'), requestVar('sticky_lday'), requestVar('sticky_lyear'));
				if ($lm >= $nowtime) {
					$limit = mysqldate($lm);
				}
				break;
		}
		
		switch($mode){
			case 'addlistitem':
				if ($itemdraft) { // be in draft
					$posttime = mysqldate($nowtime);
				}
				$query = 'INSERT INTO ' . sql_table('plug_stickyit')
				 . ' (snumber, sstart';
				if(isset($limit)) $query .= ', slimit';
				if($posttime) $query .=', spost';
				$query .= ', stask, snewcat, sgroup, srank) VALUES ('.$stickyid.', '.$start;
				if(isset($limit)) $query .= ', '.$limit;
				if($posttime) $query .= ', '.$posttime;
				$query .= ', '.$task.', '.$newcat.', '.$group.', '.$rank.')';
				
$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

				$res = mysql_query($query);
				if ($res) {
					$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=addeditem&id='.$stickyid;
					Header('Location: ' . $backto);
				} else {
					return 'line:'.__LINE__.'[mySQL]'.mysql_error();
				}
				break;
				
			case 'updatelistitem':
				$query = 'UPDATE ' . sql_table('plug_stickyit')
				. ' SET snumber=' .$stickyid. ',sstart=' .$start;
				if (isset($limit)) $query .= ', slimit=' .$limit;
				if ($posttime) $query .= ', spost=' .$posttime;
				$query .= ', stask=' .$task.', snewcat=' .$newcat.', sgroup=' .$group.', srank=' .$rank;
				$query .= ' WHERE snumber=' .$stickyid;
				
$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

				$res = mysql_query($query);
				if ($res) {
					$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=updateditem&id='.$stickyid;
					Header('Location: ' . $backto);
				} else {
					return 'line:'.__LINE__.'[mySQL]'.mysql_error();
				}
				break;
		}

$dbg_data = array('stickyid'=>$stickyid, 'spost'=>$posttime, 'sstart'=>$start, 'slimit'=>$limit, 'stask'=>$task, 'snewcat'=>$newcat, 'sgroup'=>$group, 'srank'=>$rank);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

	}
	

	/**
	  * Release item from the sticky position. This function is called from 
		*
		* "event_PreUpdateItem"($mode='releaseitem'),
		* "event_PostDeleteItem"($mode='deleteorignal'),
		* "doAction"($mode='deletlistitem').
		*
		* If it fails, this will return an error message to these functions.
	  */
	function delStickyItem($stickyid, $mode, $posttime=''){
		global $CONF, $manager;

		if ($mode != 'deleteorignal') {
			$item =& $manager->getItem($stickyid,1,0);
			if (!$item) return '[Release]Unexisting item.';
			$itemdraft = $item['draft']; 

			$rows = mysql_query('SELECT spost, stask, snewcat FROM '.sql_table('plug_stickyit')
			.' WHERE snumber='.$stickyid);
			if($rows) {
				$o = mysql_fetch_object($rows);
				switch(true){
					case !intval($o->stask) || $posttime:
						$r = mysql_query('UPDATE '.sql_table('item').' SET'
						.' itime=' .mysqldate(0).', idraft=1'
						.' WHERE inumber='.$stickyid);
						if(!$r) return 'line:'.__LINE__.'[mySQL]'.mysql_error();
						break;
					default:
 						if (!$o->snewcat) {
							$query = 'UPDATE '.sql_table('item').' SET idraft=0';
							if($itemdraft) $query .=', itime="' .$o->spost. '"';
							$query .=' WHERE inumber='.$stickyid;
						} elseif ($manager->existsCategory($o->snewcat)) {
							$curblog = getBlogIDFromItemID($stickyid);
							$aftblog = getBlogIDFromCatID($o->snewcat);
							$query = 'UPDATE '.sql_table('item').' SET';
							$query .=' idraft=0, icat='.$o->snewcat;
							if($itemdraft) $query .=', itime="' .$o->spost. '"';
							if($curblog != $aftblog) $query .=', iblog='.$aftblog;
							$query .=' WHERE inumber='.$stickyid;
						} else {
							return '[Delete]Unexisting Category.';
						}

$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

						$r =mysql_query($query);
						if(!$r) return 'line:'.__LINE__.'[mySQL]'.mysql_error();
				}
			} else {
				return '[Delete]Unexisting item.';
			}
		}
		$query = 'DELETE FROM '.sql_table('plug_stickyit')
		. ' WHERE snumber='.$stickyid;

$dbg_data = array('query'=>$query);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

		$res = mysql_query($query);
		if($mode=='deletelistitem' && $res){
			$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=deleteditem&id='.$stickyid;
			Header('Location: ' . $backto);
		}elseif(!$res){
			return 'line:'.__LINE__.'[mySQL]'.mysql_error();
		}

$dbg_data = array('stickyid'=>$stickyid, 'spost'=>$posttime, 'itime'=>$o->spost, 'sstart'=>$start, 'slimit'=>$limit, 'stask'=>$task, 'sgroup'=>$group, 'srank'=>$rank);
if(_DEBUG_MODE) debuglog($dbg_data,__LINE__,__FUNCTION__,__CLASS__,__FILE__);

	}


/**
  * Add/Update/Delete a sticky group.
	* These are required from the edit page.
	* If it fails, this will return an error message to "doAction".
	*
  */

	function addGroup() {
		global $CONF;
		$groupname = htmlspecialchars(requestVar('sgname'));
		if(!$groupname || ereg("^( |　)*$",$groupname)) {
			return;
		}
		$query = "INSERT INTO " . sql_table('plug_stickyit_group')
		. "(sgname) VALUES ('" . addslashes($groupname) . "')";
		$res = mysql_query($query);
		if ($res) {
			$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=addedgroup&id='.rawurlencode(stripslashes($groupname));
			Header('Location: ' . $backto);
		} else {
			return 'line:'.__LINE__.'[mySQL]'.mysql_error();
		}
	}
	
	function updateGroup() {
		global $CONF;
		$groupname = htmlspecialchars(requestVar('sgname'));
		if(!$groupname || preg_match("/^( |　)*$/",$groupname)) {
			$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist';
			Header('Location: ' . $backto);
		} else {
			if(preg_match("/^( |　|[0-9]|[.,_e+\-*\/'\"<>=?!#\^()[\]:;\\%&~|{}`@])+$/",$groupname)) {
				return '数字と記号だけのグループ名は作れません';
			}
			$groupid = intrequestVar('id');
			$query = "UPDATE " . sql_table('plug_stickyit_group')
			. " SET sgname='" .addslashes($groupname). "'"
			. " WHERE sgroupid=" .$groupid;
			$res = mysql_query($query);
			if ($res) {
				$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=updatedgroup&id='.rawurlencode(stripslashes($groupname));
				Header('Location: ' . $backto);
			} else {
				return 'line:'.__LINE__.'[mySQL]'.mysql_error();
			}
		}
	}
	
	function deleteGroup() {
		global $CONF;
		$groupid = intRequestVar('id');
		$groupname = requestVar('sgname');
		if($groupid < 2) return;
		$query = 'UPDATE ' . sql_table('plug_stickyit')
		. ' SET sgroup=1'
		. ' WHERE sgroup=' .$groupid;
		$res = mysql_query($query);
		if (!$res) return 'line:'.__LINE__.'[mySQL]'.mysql_error();
		
		$res = 0;
		$query = 'DELETE FROM '.sql_table('plug_stickyit_group')
		. ' WHERE sgroupid='.$groupid;
		$res = mysql_query($query);
		if($res){
			$backto = $CONF['ActionURL'].'?action=plugin&name='.$this->name.'&type=showlist&mess=deletedgroup&id='.rawurlencode($groupname);
			Header('Location: ' . $backto);
		}elseif(!$res){
			return 'line:'.__LINE__.'[mySQL]'.mysql_error();
		}
	}

	function showEditLink(){
		global $CONF;
		
		if($this->canEdit()){
			return '<a href="'.$CONF['ActionURL'].'?action=plugin&amp;name='.$this->name.'&amp;type=showlist">*申し送り事項の編集*</a>';
//元は「*Edit StickyIt*」
		}
		return;
	}

	function canEdit() {
		global $member, $manager,$tmem;
		if (!$member->isLoggedIn()) return 0;
//		return $member->isAdmin();		// これだとスーパーユーザーのみなのでコメントアウト　20040928

		return $member->isAdmin() or $manager;	// 各ブログのメンバーでもエディット可能

	}

/**
  * Methods of Nucleus Plugin.
	*
  */

	function doAction($type) {
		switch ($type){
			case 'addlistitem':
			case 'updatelistitem':
				$stickyid = intrequestVar('id');
				$res = $this->manageStickyItem($stickyid,$type);
				if($res) $this->showEditList($res); // if error
				break;
			case 'deletelistitem':
				$stickyid = intrequestVar('id');
				$res = $this->delStickyItem($stickyid,$type);
				if($res) $this->showEditList($res); // if error
				break;
			case 'showlist':
				if (!($message = requestVar('mess'))) {
					$message = '';
				}
				if (!($id = requestVar('id'))) {
					$id = '';
				}
				$this->showEditList($message, $id);
				break;
			case 'addgroup':
				$res = $this->addGroup();
				if($res) $this->showEditList($res); // if error
				break;
			case 'updategroup':
				$res = $this->updateGroup();
				if($res) $this->showEditList($res); // if error
				break;
			case 'deletegroup':
				$res = $this->deleteGroup();
				if($res) $this->showEditList($res); // if error
				break;
		}
	}

	function doTemplateVar(&$item,$type,$format='',$locale=''){
		global $CONF, $template;
		
		switch ($type) {
			case 'editlink':
				if ($item->draft) {
				$uri = $CONF['AdminURL'].'index.php?action=itemedit&amp;itemid='.$item->itemid;
				} else {
				$uri = $CONF['AdminURL'].'bookmarklet.php?action=edit&amp;itemid='.$item->itemid;
				}
				echo $uri;
				break;
			case 'editpopupcode':
				if ($item->draft) {
					$js = "if (event &amp;&amp; event.preventDefault) event.preventDefault();winbm=window.open(this.href,'nucleusbm','scrollbars=yes,width=800,height=600,left=10,top=10,status=yes,resizable=yes');winbm.focus();return false;";
				} else {
					$js = "if (event &amp;&amp; event.preventDefault) event.preventDefault();winbm=window.open(this.href,'nucleusbm','scrollbars=yes,width=600,height=500,left=10,top=10,status=yes,resizable=yes');winbm.focus();return false;";
				}
				echo $js;
				break;
			case 'date':
			case 'time':
				if ($item->draft) {
					$time = quickQuery('SELECT spost as result FROM '.sql_table('plug_stickyit').' WHERE snumber='.$item->itemid);
				} else {
					$time = $item->itime;
				}
				if($format){
					if($locale) setlocale("LC_TIME",$locale);
					echo strftime($format,strtotime($time));
				}
				break;
		}
	}
			
	function doSkinVar($skinType, $template, $stitems='', $order='', $always=0) {
		global $manager, $blog, $CONF, $catid, $blogid, $archive;
		

// distribute process
switch (strval($template)) {
	case '0':
		$params = func_get_args();
		switch ($params[2]) {
			case 'editlink':
				echo $this->showEditLink();
				break;
			case 'include':
				$always = $params[4];
				if ($page = getVar('page')){
					if ($page > 1 && !$always) return;
				}
				@readfile(BaseActions::getIncludeFileName($params[3]));
				break;
			case 'phpinclude':
				$always = $params[4];
				if ($page = getVar('page')){
					if ($page > 1 && !$always) return;
				}
				includephp(BaseActions::getIncludeFileName($params[3]));
				break;
			case 'parsedinclude':
				break;
			case 'otherblog':
				$always = $params[6];
				if ($page = getVar('page')){
					if ($page > 1 && !$always) return;
				}
				$blogid = getBlogIDFromName($params[3]);
				$b =& $manager->getBlog($blogid);
				$template = $params[4];
				$amount = 1;
				if($params[5]) $amount = $params[5];
				list($limit, $offset) = sscanf($amount, '%d(%d)');
				$b->readLog($template, $limit, $offset);
				break;
		}
		break;

	default:
// setting variables
$allFlg = 0;
switch (strtoupper($order)) {
	case 'DESC':
	case 'ASC':
		break;
	case 'ALLDESC':
	case 'ALLASC':
		$allFlg = 1;
		$order = substr($order,3);
		break;
	case 'ALL':
		$allFlg = 1;
		$order = $this->getOption('sort');
		break;
	default:
		if (is_numeric($order)) {
			$always = $order;
		}
		$order = $this->getOption('sort');
}
if ($page = getVar('page')){
	if ($page > 1 && !$always) return;
}

if ($blog) {
	$b =& $blog; 
} else {
	$b =& $manager->getBlog($CONF['DefaultBlog']);
}
$blogid = $b->getID();
$nowtime = mysqldate($b->getCorrectTime());

if (!$stitems) {
	$stickys = array(0);
} else {
	$stickys = explode("/", $stitems);
}

$st_array = array();
foreach ($stickys as $val) {
	$strows = '';
	if (!$allFlg) {
		$st_array = array();
	}
	$q_flg = 0;
	
	switch (true) {
		case is_numeric($val) && $val == 0:
			$groupitems = sql_query('SELECT snumber FROM '.sql_table('plug_stickyit').' WHERE sgroup=1 and sstart<='.$nowtime);
			
			while ($strows = mysql_fetch_row($groupitems)) {
				$st_array[] = $strows[0];
			}
			if (count($st_array)>0) {
				$from = ', '.sql_table('plug_stickyit').' as s';
				$where = ' and i.inumber=s.snumber and (i.inumber='.implode(" or i.inumber=",$st_array).') ORDER BY s.srank, i.inumber '.$order;
				$q_flg = 1;
			}
			break;
		case is_numeric($val) && $val >= 1:
			$iblog = getBlogIDFromItemID($val);
			if ($blogid == $iblog) {
				if ($allFlg) {
					$st_array[] = $val;
				} else {
					$from = '';
					$where = ' and i.inumber='.$val;
					$q_flg = 1;
				}
			}
			break;
		default:
			$sgroupid = quickQuery("SELECT sgroupid as result FROM ".sql_table('plug_stickyit_group')." WHERE sgname='".$val."'");
			if (!$sgroupid) {
				break;
			}
			$groupitems = sql_query('SELECT snumber FROM '.sql_table('plug_stickyit').' WHERE sgroup='.$sgroupid.' and sstart<='.$nowtime);
			
			while ($strows = mysql_fetch_row($groupitems)) {
				$st_array[] = $strows[0];
			}
			if (count($st_array)>0 && !$allFlg) {
				$from = ', '.sql_table('plug_stickyit').' as s';
				$where = ' and i.inumber=s.snumber and (i.inumber='.implode(" or i.inumber=",$st_array).') ORDER BY s.srank, i.inumber '.$order;
				$q_flg = 1;
			}
	}

	if ($q_flg) {
		$query = 'SELECT i.inumber as itemid, i.ititle as title, i.ibody as body, i.idraft as draft, m.mname as author, m.mrealname as authorname, UNIX_TIMESTAMP(i.itime) as timestamp, i.itime, i.imore as more, m.mnumber as authorid, c.cname as category, i.icat as catid, i.iclosed as closed';
		$query .= ' FROM '.sql_table('item').' as i, '.sql_table('member').' as m, '.sql_table('category').' as c'.$from;
		$query .= ' WHERE i.iauthor=m.mnumber and i.itime<=' . $nowtime. ' and i.icat=c.catid'.$where;

		$b->showUsingQuery($template, $query, 0, 1, $this->dateheads);

	}

}
	if ($allFlg && count($st_array)>0) {
		$query = 'SELECT i.inumber as itemid, i.ititle as title, i.ibody as body, i.idraft as draft, m.mname as author, m.mrealname as authorname, UNIX_TIMESTAMP(i.itime) as timestamp, i.itime, i.imore as more, m.mnumber as authorid, c.cname as category, i.icat as catid, i.iclosed as closed';
		$query .= ' FROM '.sql_table('item').' as i, '.sql_table('member').' as m, '.sql_table('category').' as c, '.sql_table('plug_stickyit').' as s';
		$query .= ' WHERE i.iauthor=m.mnumber and i.itime<=' . $nowtime. ' and i.icat=c.catid and i.inumber=s.snumber and (i.inumber='.implode(" or i.inumber=",$st_array).') ORDER BY i.inumber '.$order;

		$b->showUsingQuery($template, $query, 0, 1, $this->dateheads);

	}
	if($this->editlink) echo $this->showEditLink();
} // switch($template) end

// -----

	} // doSkinVar end


/**
  * Displays the StickyIt edit page.
	*
  */

	function showEditList($message='', $id='') {
		global $CONF, $member, $nucleus, $manager;

		if(!$this->canEdit()){
			Header('Location: ' . $return);
		}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=<?php echo _CHARSET ?>" />
	<title>Edit StickyIt</title>
	<link rel="stylesheet" type="text/css" href="<?php echo $CONF['AdminURL']?>styles/admin.css" />
	<link rel="stylesheet" type="text/css" href="<?php echo $CONF['AdminURL']?>styles/addedit.css" />
	<style type="text/css">
		#content{margin-left:0px}
		.defgroup{background-color: #bbc; border:none}
		.group{background-color: #ffb6c1; border:none}
		.delbutton{text-align:right;}
		.addbutton{vertical-align:middle}
		.updatebutton{text-align:center; padding-top:10px;}
		.deletearea{background-color: #eee}
		.draftflg,.error{color:#880000}
		.batchoperations{margin-right:150px;text-align:center}
	</style>
</head>
<body>

<h1>Edit StickyIt</h1>

<div id="content" class="content">
<div class="loginname">
	<?php 

		if ($member->isLoggedIn()) 
			echo _LOGGEDINAS . ' ' . $member->getDisplayName()
		    ." - <a href='".$CONF['AdminURL']."index.php?action=logout'>" . _LOGOUT. "</a>"
		    . "<br /><a href='".$CONF['AdminURL']."index.php?action=overview'>" . _ADMINHOME . "</a> - ";
		else 
			echo _NOTLOGGEDIN . ' <br />';

		echo "<a href='".$CONF['IndexURL']."'>"._YOURSITE."</a>";

?> 
	<br />(Nucleus <?php echo  $nucleus['version'] ?>)
</div>
<?php

		if ($message) {
		switch ($message) {
			case 'addeditem':
				echo '<p class="batchoperations"><strong>アイテム(ID:'.$id.')を追加しました。</strong></p>'."\n";
				break;
			case 'updateditem':
				echo '<p class="batchoperations"><strong>アイテム(ID:'.$id.')の設定を更新しました。</strong></p>'."\n";
				break;
			case 'deleteditem':
				echo '<p class="batchoperations"><strong>アイテム(ID:'.$id.')を解除しました。</strong></p>'."\n";
				break;
			case 'addedgroup':
				echo '<p class="batchoperations"><strong>グループ "'.rawurldecode($id).'" を追加しました。</strong></p>'."\n";
				break;
			case 'updatedgroup':
				echo '<p class="batchoperations"><strong>グループ "'.rawurldecode($id).'" の設定を更新しました。</strong></p>'."\n";
				break;
			case 'deletedgroup':
				echo '<p class="batchoperations"><strong>グループ "'.rawurldecode($id).'" を解除しました。</strong></p>'."\n";
				break;
			default:
				echo '<p class="batchoperations"><strong class="error">ERROR! : '.$message.'</strong></p>'."\n";
		}
		}
?>
<h2>Add Sticky Item</h2>
<p>終了日時は必要な場合だけ入力してください。また、開始日時を未来に設定した場合、アイテムをドラフトで保存していない時は、<strong>その日時まで通常アイテムとして処理されます</strong>ので注意してください。</p>
<p>Sticky解除直前に変更を行う場合は、<strong>必ず一旦Updateボタンで更新してから解除してください。</strong></p>
<p>その他詳しくは <a href="http://vivian.stripper.jp/index.php?itemid=152">説明ページ</a> をご覧下さい。</p>

<h3>新しいStickyアイテム</h3>
<form method="post" action="<?php echo $CONF['ActionURL']?>">
<div class="addform">
<ul><li><a href="<?php echo $CONF['AdminURL'].'index.php?action=createitem&amp;blogid=1' ?>">新しいアイテムの追加...</a></li>
<li>ポスト済みアイテムから追加
	<input type="hidden" name="action" value="plugin" />
	<input type="hidden" name="name" value="StickyIt" />
	<input type="hidden" name="type" value="addlistitem" />
	<table><tbody><tr><td>

ItemID: <input type="text" name="id" size="6" />
Group: 
<?php

		echo $this->showGroupForm(0);
		echo "\n".'Rank: '."\n";
		echo $this->showRankForm($this->d_rank);

?>

	</td><td rowspan="3" class="addbutton">

<input type="submit" value="Sticky It!" />

	</td></tr><tr><td>

期間指定:
<?php

		$b =& $manager->getBlog($CONF['DefaultBlog']);
		$start = getdate($b->getCorrectTime());
		$limit = array('year'=>'','mon'=>'','mday'=>'','hours'=>'','minutes'=>'');
		echo $this->showDateForm($start,$limit);

?>

	</td></tr><tr><td>

解除後の扱い:
<?php

	echo $this->showTaskForm($this->d_task);
	echo '：移行後のカテゴリー'."\n";
	echo $this->showCategoryForm();

?>

	</td></tr></tbody></table>
</li></ul>
</div>
</form>

<h3>新しいグループ</h3>
<form method="post" action="<?php echo $CONF['ActionURL']?>">
<div class="addform">
	<input type="hidden" name="action" value="plugin" />
	<input type="hidden" name="name" value="StickyIt" />
	<input type="hidden" name="type" value="addgroup" />
グループ名: <input type="text" name="sgname" size="20" />
<input type="submit" value="Add Group" />
</div>
</form>

<h2>Edit Sticky Item</h2>
<?php

		$queryGroups = 'SELECT sgroupid, sgname FROM '.sql_table('plug_stickyit_group').' ORDER BY sgroupid';
		$groups = sql_query($queryGroups);
		while ($group = mysql_fetch_object($groups)) {
			$groupid = $group->sgroupid;
?>
<table>
<tbody>
  <tr>
    <th<?php if ($groupid != 1) echo ' class="group"'; ?>><?php echo $group->sgname ?></th>
    <td class="<?php if($groupid==1) echo 'def'; ?>group">

<form method="post" action="<?php echo $CONF['ActionURL']?>">
<div class="updateform">
	<input type="hidden" name="action" value="plugin" />
	<input type="hidden" name="name" value="StickyIt" />
	<input type="hidden" name="type" value="updategroup" />
	<input type="hidden" name="id" value="<?php echo $groupid ?>" />
	Change Name: <input type="text" name="sgname" size="20" />
	<input type="submit" value="Update" />
</div>
</form>

		</td><td class="<?php if($groupid==1) echo 'def'; ?>group delbutton">
<?php

			if ($groupid > 1) {

?>

<form method="post" action="<?php echo $CONF['ActionURL']?>">
<div class="deleteform">
	<input type="hidden" name="action" value="plugin" />
	<input type="hidden" name="name" value="StickyIt" />
	<input type="hidden" name="type" value="deletegroup" />
	<input type="hidden" name="id" value="<?php echo $groupid ?>" />
	<input type="hidden" name="sgname" value="<?php echo $group->sgname ?>" />
	<input type="submit" value="Delete Group" />
</div>
</form>

<?php

			} else {
				echo " \n";
			}
			echo "		</td>\n</tr>";
			
		// item start ----------------------------
			$queryItems = 'SELECT * FROM '.sql_table('plug_stickyit')
			.' WHERE sgroup='.$groupid
			.' ORDER BY snumber '.$this->getOption('sort');
			$items = sql_query($queryItems);
			while ($sitem = mysql_fetch_object($items)) {

				$stickyid = $sitem->snumber;
				$curitem =& $manager->getItem($stickyid,1,0);
				$draft = $curitem['draft'];
				$start = $sitem->sstart;
				$limit = $sitem->slimit;
				$task = $sitem->stask;
				$newcat = $sitem->snewcat;
				$group = $groupid;
				$rank = $sitem->srank;
			
				$start = getdate(strtotime($start));
				if ($limit) {
					$limit = getdate(strtotime($limit));
				} else {
					$limit = array('year'=>'','mon'=>'','mday'=>'','hours'=>'','minutes'=>'');
				}

				$query = 'SELECT b.bname as blogname, c.catid as catid, c.cname as catname FROM '.sql_table('blog').' as b, '.sql_table('category').' as c WHERE c.catid='.$curitem['catid'].' and b.bnumber=c.cblog';
				$info = sql_query($query);
				$o = mysql_fetch_object($info);
?>
<tr>
		<td colspan="3">

<form method="post" action="<?php echo $CONF['ActionURL']?>">
<div class="updateform">
	<input type="hidden" name="action" value="plugin" />
	<input type="hidden" name="name" value="StickyIt" />
	<input type="hidden" name="type" value="updatelistitem" />
	<input type="hidden" name="id" value="<?php echo $stickyid ?>" />
	<table>
		<tbody><tr>
			<td class="future">ItemID:<?php echo $stickyid; if($draft) echo '<br /><span class="draftflg">ドラフトアイテム</span>'; ?></td>
			<td class="future"><?php echo '['.$o->blogname.'/'.$o->catname.'] '.$curitem['title'] ?></td>
			<td>Group:
<?php
				echo $this->showGroupForm($group);
?>
			</td><td>Rank
<?php
				echo $this->showRankForm($rank);
?>
			</td>
			<td rowspan="3" class="updatebutton"><input type="submit" value="Update" /></td>
			<td class="deletearea"> </td>
		</tr><tr>
			<td>期間設定</td>
			<td colspan="3">
<?php
				echo $this->showDateForm($start,$limit);
?>
			</td>
			<td class="deletearea"><a href="<?php echo $CONF['AdminURL'].'index.php?action=itemedit&amp;itemid='.$stickyid ?>">アイテムの編集</a></td>
		</tr><tr>
			<td>解除後の扱い</td>
			<td colspan="3">
<?php
				echo $this->showTaskForm($task);
				echo '：移行後のカテゴリー'."\n";
				$selected = $newcat;
				echo $this->showCategoryForm($selected);
?>
			</td>
			<td class="deletearea"><a href="action.php?action=plugin&name=StickyIt&type=deletelistitem&id=<?php echo $stickyid ?>">Sticky解除</a></td>
		</tr></tbody>
	</table>
</div>
</form>

<?php
			}
		// item end ----------------------------
?>
		</td>
	</tr>
</tbody>
</table>

<?php
		} // group end
?>
</body>
</html>
<?php

	} // showEditList end




/**
  * Helper functions.
  *
  * Inserts a HTML select/input element common to each form.
  */

	/**
	  * Groups
	  */
	function showGroupForm($selected) {
		$buf = '<select name="sticky_group">';
		$res = sql_query('SELECT sgroupid, sgname FROM '.sql_table('plug_stickyit_group'));
		while ($obj = mysql_fetch_object($res)) {
			$buf.= '	<option value="'.$obj->sgroupid.'"';
			if ($obj->sgroupid == $selected) {
				$buf.= ' selected="selected"';
			}
			$buf.= '>'.$obj->sgname.'</option>'."\n";
		}
		$buf .= '</select>'."\n";
		return $buf;
	}

	/**
	  * Rank
	  */
	function showRankForm($selected){
		$buf = '<select name="sticky_rank">';
		for($i=0; $i<($this->maxrank); $i++){
			$buf.= '	<option value="'.$i.'"';
			if($selected == $i){
				$buf.= ' selected="selected"';
			}
			$buf.= '>'.$i.'</option>'."\n";
		}
		$buf .= '</select>'."\n";
		return $buf;
	}

	/**
	  * Period
	  */
	function showDateForm($start, $limit) {
		$buf = <<<EOD
<input type="text" name="sticky_syear" size="4" style="width:40px" value="{$start['year']}" /> 年
<input type="text" name="sticky_smonth" size="2" style="width:20px" value="{$start['mon']}" /> 月
<input type="text" name="sticky_sday" size="2" style="width:20px" value="{$start['mday']}" /> 日
<input type="text" name="sticky_shour" size="2" style="width:20px" value="{$start['hours']}" /> 時
<input type="text" name="sticky_sminutes" size="2" style="width:20px" value="{$start['minutes']}" /> 分
から
<input type="text" name="sticky_lyear" size="4" style="width:40px" value="{$limit['year']}" /> 年
<input type="text" name="sticky_lmonth" size="2" style="width:20px" value="{$limit['mon']}" /> 月
<input type="text" name="sticky_lday" size="2" style="width:20px" value="{$limit['mday']}" /> 日
<input type="text" name="sticky_lhour" size="2" style="width:20px" value="{$limit['hours']}" /> 時
<input type="text" name="sticky_lminutes" size="2" style="width:20px" value="{$limit['minutes']}" /> 分
まで

EOD;

		return $buf;
	}

	/**
	  * Task
	  */
	function showTaskForm($task){
		$buf = '<input type="radio" value="0" id="plug_sticky_hide" name="sticky_task"';
		if($task < 1) $buf .= ' checked="checked"';
		$buf .= ' />'."\n";
		$buf .= '<label for="plug_sticky_hide">ドラフト保存</label>'."\n";
		$buf .= '<input type="radio" value="1" id="plug_sticky_normal" name="sticky_task"';
		if($task >= 1) $buf .= ' checked="checked"';
		$buf .= ' />'."\n";
		$buf .= '<label for="plug_sticky_normal">通常アイテムに移行</label>'."\n";
		
		return $buf;
	}

	/**
	  * All blogs to which the user has access
	  *
	  * This function copied "ADMIN::selectBlog(ADMIN.php)".
	  */
	function showCategoryForm($selected = 0, $tabindex = 0) {
		global $member, $CONF;
		
		$name = 'sticky_cat';

		// 0. get IDs of blogs to which member can post items
		if (($member->isAdmin()) && ($CONF['ShowAllBlogs'])) {
			$queryBlogs =  'SELECT bnumber FROM '.sql_table('blog').' ORDER BY bname';
		} else {
			$queryBlogs =  'SELECT bnumber FROM '.sql_table('blog').', '.sql_table('team').' WHERE tblog=bnumber and tmember=' . $member->getID();
		}
		$rblogids = sql_query($queryBlogs);
		while ($o = mysql_fetch_object($rblogids)) {
			$aBlogIds[] = intval($o->bnumber);
		}
		if (count($aBlogIds) == 0) {
			return;
		}

		echo '<select name="',$name,'" tabindex="',$tabindex,'">';
		if (!$selected) {
			echo '<option value="0" selected="selected">--変更なし--</option>';
		}

		// 1. select blogs (we'll create optiongroups)
		// (only select those blogs that have the user on the team)
		$queryBlogs =  'SELECT bnumber, bname FROM '.sql_table('blog').' WHERE bnumber in ('.implode(',',$aBlogIds).') ORDER BY bname';
		$blogs = sql_query($queryBlogs);
		if (mysql_num_rows($blogs) > 1) {
			$multipleBlogs = 1;
		}

		while ($oBlog = mysql_fetch_object($blogs)) {
			if ($multipleBlogs) {
				echo '<optgroup label="',htmlspecialchars($oBlog->bname),'">';
			}
		
			// 2. for each category in that blog
			$categories = sql_query('SELECT cname, catid FROM '.sql_table('category').' WHERE cblog=' . $oBlog->bnumber . ' ORDER BY cname ASC');
			while ($oCat = mysql_fetch_object($categories)) {
				if ($oCat->catid == $selected)
					$selectText = ' selected="selected" ';
				else
					$selectText = '';
				echo '<option value="',$oCat->catid,'" ', $selectText,'>',htmlspecialchars($oCat->cname),'</option>';
			}

			if ($multipleBlogs) {
				echo '</optgroup>';
			}
		
		}
		echo '</select>';
		
	}

} // class end
?>