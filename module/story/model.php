<?php
/**
 * The model file of story module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2015 青岛易软天创网络科技有限公司(QingDao Nature Easy Soft Network Technology Co,LTD, www.cnezsoft.com)
 * @license     ZPL (http://zpl.pub/page/zplv11.html)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     story
 * @version     $Id: model.php 5145 2013-07-15 06:47:26Z chencongzhi520@gmail.com $
 * @link        http://www.zentao.net
 */
?>
<?php
class storyModel extends model
{
    /**
     * Get a story by id.
     * 
     * @param  int    $storyID 
     * @param  int    $version 
     * @param  bool   $setImgSize
     * @access public
     * @return object|bool
     */
    public function getById($storyID, $version = 0, $setImgSize = false)
    {
        $story = $this->dao->findById((int)$storyID)->from(TABLE_STORY)->fetch();
        if(!$story) return false;
        if(substr($story->closedDate, 0, 4) == '0000') $story->closedDate = '';
        if($version == 0) $version = $story->version;
        $spec = $this->dao->select('title,spec,verify')->from(TABLE_STORYSPEC)->where('story')->eq($storyID)->andWhere('version')->eq($version)->fetch();
        $story->title  = isset($spec->title)  ? $spec->title  : '';
        $story->spec   = isset($spec->spec)   ? $spec->spec   : '';
        $story->verify = isset($spec->verify) ? $spec->verify : '';

        if($setImgSize) $story->spec   = $this->loadModel('file')->setImgSize($story->spec);
        if($setImgSize) $story->verify = $this->file->setImgSize($story->verify);

        $story->projects = $this->dao->select('t1.project, t2.name, t2.status')->from(TABLE_PROJECTSTORY)->alias('t1')
            ->leftJoin(TABLE_PROJECT)->alias('t2')->on('t1.project = t2.id')
            ->where('t1.story')->eq($storyID)
            ->orderBy('t1.project DESC')
            ->fetchAll('project');
        $story->tasks     = $this->dao->select('id, name, assignedTo, project, status, consumed, `left`')->from(TABLE_TASK)->where('story')->eq($storyID)->andWhere('deleted')->eq(0)->orderBy('id DESC')->fetchGroup('project');
        //$story->bugCount  = $this->dao->select('COUNT(*)')->alias('count')->from(TABLE_BUG)->where('story')->eq($storyID)->fetch('count');
        //$story->caseCount = $this->dao->select('COUNT(*)')->alias('count')->from(TABLE_CASE)->where('story')->eq($storyID)->fetch('count');
        if($story->toBug) $story->toBugTitle = $this->dao->findById($story->toBug)->from(TABLE_BUG)->fetch('title');
        if($story->plan)  $story->planTitle  = $this->dao->findById($story->plan)->from(TABLE_PRODUCTPLAN)->fetch('title');
        $extraStories = array();
        if($story->duplicateStory) $extraStories = array($story->duplicateStory);
        if($story->linkStories)    $extraStories = explode(',', $story->linkStories);
        if($story->childStories)   $extraStories = array_merge($extraStories, explode(',', $story->childStories));
        $extraStories = array_unique($extraStories);
        if(!empty($extraStories)) $story->extraStories = $this->dao->select('id,title')->from(TABLE_STORY)->where('id')->in($extraStories)->fetchPairs();
        return $story;
    }

    /**
     * Get stories by idList.
     * 
     * @param  int|array|string    $storyIDList 
     * @access public
     * @return array
     */
    public function getByList($storyIDList = 0)
    {
        return $this->dao->select('t1.*, t2.spec, t2.verify')->from(TABLE_STORY)->alias('t1')
            ->leftJoin(TABLE_STORYSPEC)->alias('t2')->on('t1.id=t2.story')
            ->where('t1.deleted')->eq(0)
            ->andWhere('t1.version=t2.version')
            ->beginIF($storyIDList)->andWhere('t1.id')->in($storyIDList)->fi()
            ->fetchAll('id');
    }

    /**
     * Get affected things. 
     * 
     * @param  object  $story 
     * @access public
     * @return object
     */
    public function getAffectedScope($story)
    {
        /* Remove closed projects. */
        if($story->projects)
        {
            foreach($story->projects as $projectID => $project) if($project->status == 'done') unset($story->projects[$projectID]);
        }

        /* Get team members. */
        if($story->projects)
        {
            $story->teams = $this->dao->select('account, project')
                ->from(TABLE_TEAM)
                ->where('project')->in(array_keys($story->projects))
                ->fetchGroup('project');
        }

        /* Get affected bugs. */
        $story->bugs = $this->dao->findByStory($story->id)->from(TABLE_BUG)
            ->andWhere('status')->ne('closed')
            ->andWhere('deleted')->eq(0)
            ->orderBy('id desc')->fetchAll();

        /* Get affected cases. */
        $story->cases = $this->dao->findByStory($story->id)->from(TABLE_CASE)->andWhere('deleted')->eq(0)->fetchAll();

        return $story;
    }

    /**
     * Create a story.
     * 
     * @access public
     * @return int|bool the id of the created story or false when error.
     */
    public function create($projectID = 0, $bugID = 0)
    {
        $now   = helper::now();
        $story = fixer::input('post')
            ->cleanInt('product,module,pri,plan')
            ->cleanFloat('estimate')
            ->callFunc('title', 'trim')
            ->setDefault('plan', 0)
            ->add('openedBy', $this->app->user->account)
            ->add('openedDate', $now)
            ->add('assignedDate', 0)
            ->add('version', 1)
            ->add('status', 'draft')
            ->setIF($this->post->assignedTo != '', 'assignedDate', $now)
            ->setIF($this->post->needNotReview or $projectID > 0, 'status', 'active')
            ->setIF($this->post->plan > 0, 'stage', 'planned')
            ->setIF($projectID > 0, 'stage', 'projected')
            ->setIF($bugID > 0, 'fromBug', $bugID)
            ->join('mailto', ',')
            ->stripTags($this->config->story->editor->create['id'], $this->config->allowedTags)
            ->remove('files,labels,needNotReview,newStory')
            ->get();

        /* Check repeat story. */
        $result = $this->loadModel('common')->removeDuplicate('story', $story, "product={$story->product}");
        if($result['stop']) return array('status' => 'exists', 'id' => $result['duplicate']);

        $this->dao->insert(TABLE_STORY)->data($story, 'spec,verify')->autoCheck()->batchCheck($this->config->story->create->requiredFields, 'notempty')->exec();
        if(!dao::isError())
        {
            $storyID = $this->dao->lastInsertID();
            $this->loadModel('file')->saveUpload('story', $storyID, $extra = 1);

            $data          = new stdclass();
            $data->story   = $storyID;
            $data->version = 1;
            $data->title   = $story->title;
            $data->spec    = $story->spec;
            $data->verify  = $story->verify;
            $this->dao->insert(TABLE_STORYSPEC)->data($data)->exec();

            if($projectID != 0) 
            {
                $this->dao->insert(TABLE_PROJECTSTORY)
                    ->set('project')->eq($projectID)
                    ->set('product')->eq($this->post->product)
                    ->set('story')->eq($storyID)
                    ->set('version')->eq(1)
                    ->exec();
            }
            
            if($bugID > 0)
            {
                $bug = new stdclass();
                $bug->toStory      = $storyID;
                $bug->status       = 'closed';
                $bug->resolution   = 'tostory';
                $bug->resolvedBy   = $this->app->user->account;
                $bug->resolvedDate = $now;
                $bug->closedBy     = $this->app->user->account;
                $bug->closedDate   = $now;
                $bug->assignedTo   = 'closed';
                $bug->assignedDate = $now;
                $this->dao->update(TABLE_BUG)->data($bug)->where('id')->eq($bugID)->exec();
    
                $this->loadModel('action')->create('bug', $bugID, 'ToStory', '', $storyID);
                $this->action->create('bug', $bugID, 'Closed');

                /* add files to story from bug. */
                $files = $this->dao->select('*')->from(TABLE_FILE)
                    ->where('objectType')->eq('bug')
                    ->andWhere('objectID')->eq($bugID)
                    ->fetchAll();
                if(!empty($files)) 
                { 
                    foreach($files as $file)
                    {
                        $file->objectType = 'story';
                        $file->objectID = $storyID; 
                        unset($file->id); 
                        $this->dao->insert(TABLE_FILE)->data($file)->exec();
                    }
                }
            }
            return array('status' => 'created', 'id' => $storyID);
        }
        return false;
    }

    /**
     * Create a batch stories.
     * 
     * @access public
     * @return int|bool the id of the created story or false when error.
     */
    public function batchCreate($productID = 0)
    {
        $now      = helper::now();
        $stories  = fixer::input('post')->get();
        $batchNum = count(reset($stories));

        $result  = $this->loadModel('common')->removeDuplicate('story', $stories, "product={$productID}");
        $stories = $result['data'];

        $module = 0;
        $plan   = 0;
        $pri    = 0;
        for($i = 0; $i < $batchNum; $i++)
        {
            $module = $stories->module[$i] == 'same' ? $module : $stories->module[$i];
            $plan   = $stories->plan[$i]   == 'same' ? $plan   : $stories->plan[$i];
            $pri    = $stories->pri[$i]    == 'same' ? $pri   : $stories->pri[$i];
            $stories->module[$i] = (int)$module;
            $stories->plan[$i]   = (int)$plan;
            $stories->pri[$i]    = (int)$pri;
        }

        if(isset($stories->uploadImage)) $this->loadModel('file');
        for($i = 0; $i < $batchNum; $i++)
        {
            if($stories->title[$i] != '')
            {
                $data = new stdclass();
                $data->module     = $stories->module[$i];
                $data->plan       = $stories->plan[$i];
                $data->title      = $stories->title[$i];
                $data->pri        = $stories->pri[$i] != '' ?      $stories->pri[$i] : 0;
                $data->estimate   = $stories->estimate[$i] != '' ? $stories->estimate[$i] : 0;
                $data->status     = $stories->needReview[$i] == 0 ? 'active' : 'draft';
                $data->product    = $productID;
                $data->openedBy   = $this->app->user->account;
                $data->openedDate = $now;
                $data->version    = 1;

                $this->dao->insert(TABLE_STORY)->data($data)->autoCheck()
                    ->batchCheck($this->config->story->create->requiredFields, 'notempty')
                    ->exec();
                if(dao::isError()) 
                {
                    echo js::error(dao::getError());
                    die(js::reload('parent'));
                }

                $storyID = $this->dao->lastInsertID();
                $this->setStage($storyID);

                $specData = new stdclass();
                $specData->story   = $storyID;
                $specData->version = 1;
                $specData->title   = $stories->title[$i];
                if($stories->spec[$i] != '') $specData->spec = nl2br($stories->spec[$i]);

                if(!empty($stories->uploadImage[$i]))
                {
                    $fileName  = htmlspecialchars_decode($stories->uploadImage[$i]);
                    $realPath  = $this->session->storyImagesFile . $fileName;

                    $file = array();
                    $file['extension'] = $this->file->getExtension($fileName);
                    $file['pathname']  = $this->file->setPathName($i, $file['extension']);
                    $file['title']     = str_replace(".{$file['extension']}", '', $fileName);
                    $file['size']      = filesize($realPath);
                    if(rename($realPath, $this->file->savePath . $file['pathname']))
                    {
                        $file['addedBy']    = $this->app->user->account;    
                        $file['addedDate']  = $now;     
                        if(in_array($file['extension'], $this->config->file->imageExtensions))
                        {
                            $this->dao->insert(TABLE_FILE)->data($file)->exec();

                            $url = $this->file->webPath . $file['pathname'];
                            $specData->spec .= '<img src="' . $url . '" alt="" />';
                        }
                        else
                        {
                            $file['objectType'] = 'story';
                            $file['objectID']   = $storyID;
                            $this->dao->insert(TABLE_FILE)->data($file)->exec();
                        }
                    }
                }

                $this->dao->insert(TABLE_STORYSPEC)->data($specData)->exec();

                $this->loadModel('action');
                $actionID = $this->action->create('story', $storyID, 'Opened', '');
                $mails[$i] = new stdclass();
                $mails[$i]->storyID  = $storyID;
                $mails[$i]->actionID = $actionID;
            }
        }

        /* Remove upload image file and session. */
        if(!empty($stories->uploadImage) and $this->session->storyImagesFile)
        {
            $classFile = $this->app->loadClass('zfile'); 
            if(is_dir($this->session->storyImagesFile)) $classFile->removeDir($this->session->storyImagesFile);
            unset($_SESSION['storyImagesFile']);
        }

        return $mails;
    }

    /**
     * Change a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return array  the change of the story.
     */
    public function change($storyID)
    {
        $specChanged = false;
        $oldStory    = $this->getById($storyID);
        $story       = fixer::input('post')->stripTags($this->config->story->editor->change['id'], $this->config->allowedTags)->get();
        if($story->spec != $oldStory->spec or $story->verify != $oldStory->verify or $story->title != $oldStory->title or $this->loadModel('file')->getCount()) $specChanged = true;

        $now   = helper::now();
        $story = fixer::input('post')
            ->callFunc('title', 'trim')
            ->add('lastEditedBy', $this->app->user->account)
            ->add('lastEditedDate', $now)
            ->setIF($this->post->assignedTo != $oldStory->assignedTo, 'assignedDate', $now)
            ->setIF($specChanged, 'version', $oldStory->version + 1)
            ->setIF($specChanged and $oldStory->status == 'active' and $this->post->needNotReview == false, 'status',  'changed')
            ->setIF($specChanged and $oldStory->status == 'draft'  and $this->post->needNotReview, 'status', 'active')
            ->setIF($specChanged, 'reviewedBy',  '')
            ->setIF($specChanged, 'closedBy', '')
            ->setIF($specChanged, 'closedReason', '')
            ->setIF($specChanged and $oldStory->reviewedBy, 'reviewedDate',  '0000-00-00')
            ->setIF($specChanged and $oldStory->closedBy,   'closedDate',   '0000-00-00')
            ->stripTags($this->config->story->editor->change['id'], $this->config->allowedTags)
            ->remove('files,labels,comment,needNotReview')
            ->get();
        $this->dao->update(TABLE_STORY)
            ->data($story, 'spec,verify')
            ->autoCheck()
            ->batchCheck($this->config->story->change->requiredFields, 'notempty')
            ->where('id')->eq((int)$storyID)->exec();
        if(!dao::isError())
        {
            if($specChanged)
            {
                $data          = new stdclass();
                $data->story   = $storyID;
                $data->version = $oldStory->version + 1;
                $data->title   = $story->title;
                $data->spec    = $story->spec;
                $data->verify  = $story->verify;
                $this->dao->insert(TABLE_STORYSPEC)->data($data)->exec();
            }
            else
            {
                unset($story->spec);
                unset($oldStory->spec);
            }
            return common::createChanges($oldStory, $story);
        }
    }

    /**
     * Update a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return array the changes of the story.
     */
    public function update($storyID)
    {
        $now      = helper::now();
        $oldStory = $this->getById($storyID);

        $story = fixer::input('post')
            ->cleanInt('product,module,pri,plan')
            ->add('assignedDate', $oldStory->assignedDate)
            ->add('lastEditedBy', $this->app->user->account)
            ->add('lastEditedDate', $now)
            ->setDefault('status', $oldStory->status)
            ->setIF($this->post->plan !== false and $this->post->plan == '', 'plan', 0)
            ->setIF($this->post->assignedTo   != $oldStory->assignedTo, 'assignedDate', $now)
            ->setIF($this->post->closedBy     != false and $oldStory->closedDate == '', 'closedDate', $now)
            ->setIF($this->post->closedReason != false and $oldStory->closedDate == '', 'closedDate', $now)
            ->setIF($this->post->closedBy     != false or  $this->post->closedReason != false, 'status', 'closed')
            ->setIF($this->post->closedReason != false and $this->post->closedBy     == false, 'closedBy', $this->app->user->account)
            ->join('reviewedBy', ',')
            ->join('mailto', ',')
            ->remove('files,labels,comment')
            ->get();

        $this->dao->update(TABLE_STORY)
            ->data($story)
            ->autoCheck()
            ->batchCheck($this->config->story->edit->requiredFields, 'notempty')
            ->checkIF(isset($story->closedBy), 'closedReason', 'notempty')
            ->checkIF(isset($story->closedReason) and $story->closedReason == 'done', 'stage', 'notempty')
            ->checkIF(isset($story->closedReason) and $story->closedReason == 'duplicate',  'duplicateStory', 'notempty')
            ->checkIF(isset($story->closedReason) and $story->closedReason == 'subdivided', 'childStories', 'notempty')
            ->where('id')->eq((int)$storyID)->exec();

        if(!dao::isError()) return common::createChanges($oldStory, $story);
    }

    /**
     * Batch update stories.
     * 
     * @access public
     * @return array.
     */
    public function batchUpdate()
    {
        /* Init vars. */
        $stories     = array();
        $allChanges  = array();
        $now         = helper::now();
        $data        = fixer::input('post')->get();
        $storyIDList = $this->post->storyIDList ? $this->post->storyIDList : array();

        /* Adjust whether the post data is complete, if not, remove the last element of $taskIDList. */
        if($this->session->showSuhosinInfo) array_pop($storyIDList);

        /* Init $stories. */
        if(!empty($storyIDList))
        {
            $oldStories = $this->getByList($storyIDList);
            foreach($storyIDList as $storyID)
            {
                $oldStory = $oldStories[$storyID];

                $story                 = new stdclass();
                $story->lastEditedBy   = $this->app->user->account;
                $story->lastEditedDate = $now;
                $story->status         = $oldStory->status;
                $story->title          = $data->titles[$storyID];
                $story->estimate       = $data->estimates[$storyID];
                $story->pri            = $data->pris[$storyID];
                $story->module         = $data->modules[$storyID];
                $story->plan           = $data->plans[$storyID];
                $story->source         = $data->sources[$storyID];
                $story->stage          = isset($data->stages[$storyID])             ? $data->stages[$storyID]             : $oldStory->stage;
                $story->closedBy       = isset($data->closedBys[$storyID])          ? $data->closedBys[$storyID]          : $oldStory->closedBy;
                $story->closedReason   = isset($data->closedReasons[$storyID])      ? $data->closedReasons[$storyID]      : $oldStory->closedReason;
                $story->duplicateStory = isset($data->duplicateStories[$storyID])   ? $data->duplicateStories[$storyID]   : $oldStory->duplicateStory;
                $story->childStories   = isset($data->childStoriesIDList[$storyID]) ? $data->childStoriesIDList[$storyID] : $oldStory->childStories;
                $story->version        = $story->title == $oldStory->title ? $oldStory->version : $oldStory->version + 1;

                if($story->title        != $oldStory->title)                         $story->status     = 'changed';
                if($story->plan         !== false and $story->plan == '')            $story->plan       = 0;
                if($story->closedBy     != false  and $oldStory->closedDate == '')   $story->closedDate = $now;
                if($story->closedReason != false  and $oldStory->closedDate == '')   $story->closedDate = $now;
                if($story->closedBy     != false  or  $story->closedReason != false) $story->status     = 'closed';
                if($story->closedReason != false  and $story->closedBy     == false) $story->closedBy   = $this->app->user->account;

                $stories[$storyID] = $story;
            }

            foreach($stories as $storyID => $story)
            {
                $oldStory = $oldStories[$storyID];

                $this->dao->update(TABLE_STORY)->data($story)
                    ->autoCheck()
                    ->batchCheck($this->config->story->edit->requiredFields, 'notempty')
                    ->checkIF($story->closedBy, 'closedReason', 'notempty')
                    ->checkIF($story->closedReason == 'done', 'stage', 'notempty')
                    ->checkIF($story->closedReason == 'duplicate',  'duplicateStory', 'notempty')
                    ->checkIF($story->closedReason == 'subdivided', 'childStories', 'notempty')
                    ->where('id')->eq((int)$storyID)
                    ->exec();
                if($story->title != $oldStory->title)
                {
                    $data          = new stdclass();
                    $data->story   = $storyID;
                    $data->version = $story->version;
                    $data->title   = $story->title;
                    $data->spec    = $oldStory->spec;
                    $data->verify  = $oldStory->verify;
                    $this->dao->insert(TABLE_STORYSPEC)->data($data)->exec();
                }

                if(!dao::isError()) 
                {
                    $allChanges[$storyID] = common::createChanges($oldStory, $story);
                }
                else
                {
                    die(js::error('story#' . $storyID . dao::getError(true)));
                }
            }
        }

        return $allChanges;
    }

    /**
     * Review a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return bool
     */
    public function review($storyID)
    {
        if($this->post->result == false)   die(js::alert($this->lang->story->mustChooseResult));
        if($this->post->result == 'revert' and $this->post->preVersion == false) die(js::alert($this->lang->story->mustChoosePreVersion));

        $oldStory = $this->dao->findById($storyID)->from(TABLE_STORY)->fetch();
        $now      = helper::now();
        $date     = helper::today();
        $story = fixer::input('post')
            ->remove('result,preVersion,comment')
            ->setDefault('reviewedDate', $date)
            ->add('lastEditedBy', $this->app->user->account)
            ->add('lastEditedDate', $now)
            ->setIF($this->post->result == 'pass' and $oldStory->status == 'draft',   'status', 'active')
            ->setIF($this->post->result == 'pass' and $oldStory->status == 'changed', 'status', 'active')
            ->setIF($this->post->result == 'reject', 'closedBy',   $this->app->user->account)
            ->setIF($this->post->result == 'reject', 'closedDate', $now)
            ->setIF($this->post->result == 'reject', 'assignedTo', 'closed')
            ->setIF($this->post->result == 'reject', 'status', 'closed')
            ->setIF($this->post->result == 'revert', 'version', $this->post->preVersion)
            ->setIF($this->post->result == 'revert', 'status',  'active')
            ->setIF($this->post->closedReason == 'done', 'stage', 'released')
            ->removeIF($this->post->result != 'reject', 'closedReason, duplicateStory, childStories')
            ->removeIF($this->post->result == 'reject' and $this->post->closedReason != 'duplicate', 'duplicateStory')
            ->removeIF($this->post->result == 'reject' and $this->post->closedReason != 'subdivided', 'childStories')
            ->join('reviewedBy', ',')
            ->get();

        /* fix bug #671. */
        $this->lang->story->closedReason = $this->lang->story->rejectedReason;

        $this->dao->update(TABLE_STORY)->data($story)
            ->autoCheck()
            ->batchCheck($this->config->story->review->requiredFields, 'notempty')
            ->checkIF($this->post->result == 'reject', 'closedReason', 'notempty')
            ->checkIF($this->post->result == 'reject' and $this->post->closedReason == 'duplicate',  'duplicateStory', 'notempty')
            ->checkIF($this->post->result == 'reject' and $this->post->closedReason == 'subdivided', 'childStories',   'notempty')
            ->where('id')->eq($storyID)->exec();
        if($this->post->result == 'revert')
        {
            $preTitle = $this->dao->select('title')->from(TABLE_STORYSPEC)->where('story')->eq($storyID)->andWHere('version')->eq($this->post->preVersion)->fetch('title');
            $this->dao->update(TABLE_STORY)->set('title')->eq($preTitle)->where('id')->eq($storyID)->exec();
            $this->dao->delete()->from(TABLE_STORYSPEC)->where('story')->eq($storyID)->andWHere('version')->eq($oldStory->version)->exec();
            $this->dao->delete()->from(TABLE_FILE)->where('objectType')->eq('story')->andWhere('objectID')->eq($storyID)->andWhere('extra')->eq($oldStory->version)->exec();
        }
        $this->setStage($storyID);
        return true;
    }

    /**
     * Batch review stories.
     * 
     * @param  array   $storyIDList 
     * @access public
     * @return array
     */
    function batchReview($storyIDList, $result, $reason)
    {
        $now     = helper::now();
        $date    = helper::today();
        $actions = array();
        $this->loadModel('action');

        $oldStories = $this->getByList($storyIDList);
        foreach($storyIDList as $storyID)
        {
            $oldStory = $oldStories[$storyID];
            if($oldStory->status != 'draft' and $oldStory->status != 'changed') continue;

            $story = new stdClass();
            $story->reviewedDate   = $date;
            $story->lastEditedBy   = $this->app->user->account;
            $story->lastEditedDate = $now;
            if($result == 'pass') $story->status = 'active';
            if($reason == 'done') $story->stage = 'released';
            if($result == 'reject')
            {
                $story->status     = 'closed';
                $story->closedBy   = $this->app->user->account;
                $story->closedDate = $now;
                $story->assignedTo = closed;
                $this->action->create('story', $storyID, 'Closed', '', ucfirst($reason));
            }

            $this->dao->update(TABLE_STORY)->data($story)->autoCheck()->where('id')->eq($storyID)->exec();
            $this->setStage($storyID);

            if($reason and strpos('done,postponed', $reason) !== false) $result = 'pass';
            $actions[$storyID] = $this->action->create('story', $storyID, 'Reviewed', '', ucfirst($result));
            $this->action->logHistory($actions[$storyID], array());
        }

        return $actions;
    }

    /**
     * Close a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return bool
     */
    public function close($storyID)
    {
        $oldStory = $this->dao->findById($storyID)->from(TABLE_STORY)->fetch();
        $now      = helper::now();
        $story = fixer::input('post')
            ->add('lastEditedBy', $this->app->user->account)
            ->add('lastEditedDate', $now)
            ->add('closedDate', $now)
            ->add('closedBy',   $this->app->user->account)
            ->add('assignedTo',   'closed')
            ->add('assignedDate', $now)
            ->add('status', 'closed') 
            ->removeIF($this->post->closedReason != 'duplicate', 'duplicateStory')
            ->removeIF($this->post->closedReason != 'subdivided', 'childStories')
            ->setIF($this->post->closedReason == 'done', 'stage', 'released')
            ->setIF($this->post->closedReason != 'done', 'plan', 0)
            ->remove('comment')
            ->get();
        $this->dao->update(TABLE_STORY)->data($story)
            ->autoCheck()
            ->batchCheck($this->config->story->close->requiredFields, 'notempty')
            ->checkIF($story->closedReason == 'duplicate',  'duplicateStory', 'notempty')
            ->checkIF($story->closedReason == 'subdivided', 'childStories',   'notempty')
            ->where('id')->eq($storyID)->exec();
        return common::createChanges($oldStory, $story);
    }

    /**
     * Batch close story.
     * 
     * @access public
     * @return void
     */
    public function batchClose()
    {
        /* Init vars. */
        $stories     = array();
        $allChanges  = array();
        $now         = helper::now();
        $storyIDList = $this->post->storyIDList ? $this->post->storyIDList : array();

        /* Adjust whether the post data is complete, if not, remove the last element of $storyIDList. */
        if($this->session->showSuhosinInfo) array_pop($storyIDList);
        $oldStories = $this->getByList($storyIDList);
        foreach($storyIDList as $storyID)
        {
            $oldStory = $oldStories[$storyID];
            if($oldStory->status == 'closed') continue;

            $story = new stdclass();
            $story->lastEditedBy   = $this->app->user->account;
            $story->lastEditedDate = $now;
            $story->closedBy       = $this->app->user->account;
            $story->closedDate     = $now;
            $story->assignedTo     = 'closed';
            $story->assignedDate   = $now;
            $story->status         = 'closed';

            $story->closedReason   = $this->post->closedReasons[$storyID];
            $story->duplicateStory = $this->post->duplicateStoryIDList[$storyID] ? $this->post->duplicateStoryIDList[$storyID] : $oldStory->duplicateStory;
            $story->childStories   = $this->post->childStoriesIDList[$storyID] ? $this->post->childStoriesIDList[$storyID] : $oldStory->childStories;

            if($story->closedReason == 'done') $story->stage = 'released';
            if($story->closedReason != 'done') $story->plan  = 0;

            $stories[$storyID] = $story;
            unset($story);
        }

        foreach($stories as $storyID => $story)
        {
            if(!$story->closedReason) continue;

            $oldStory = $oldStories[$storyID];

            $this->dao->update(TABLE_STORY)->data($story)
                ->autoCheck()
                ->checkIF($story->closedReason == 'duplicate',  'duplicateStory', 'notempty')
                ->checkIF($story->closedReason == 'subdivided', 'childStories',   'notempty')
                ->where('id')->eq($storyID)->exec();

            if(!dao::isError()) 
            {
                $allChanges[$storyID] = common::createChanges($oldStory, $story);
            }
            else
            {
                die(js::error('story#' . $storyID . dao::getError(true)));
            }
        }

        return $allChanges;
    }

    /**
     * Batch change the plan of story.
     * 
     * @param  array  $storyIDList 
     * @param  int    $planID 
     * @access public
     * @return array 
     */
    public function batchChangePlan($storyIDList, $planID)
    {
        $now         = helper::now();
        $allChanges  = array();
        $oldStories  = $this->getByList($storyIDList);
        foreach($storyIDList as $storyID)
        {
            $oldStory = $oldStories[$storyID];

            $story = new stdclass();
            $story->lastEditedBy   = $this->app->user->account;
            $story->lastEditedDate = $now;
            $story->plan           = $planID;
            if($planID) $story->stage = 'planned';

            $this->dao->update(TABLE_STORY)->data($story)->autoCheck()->where('id')->eq((int)$storyID)->exec();
            if(!$planID) $this->setStage($storyID);
            if(!dao::isError()) $allChanges[$storyID] = common::createChanges($oldStory, $story);
        }
        return $allChanges;
    }

    /**
     * Batch change the stage of story.
     * 
     * @param  string    $stage 
     * @access public
     * @return array
     */
    public function batchChangeStage($storyIDList, $stage)
    {
        $now         = helper::now();
        $allChanges  = array();
        $oldStories  = $this->getByList($storyIDList);
        foreach($storyIDList as $storyID)
        {
            $oldStory = $oldStories[$storyID];
            if($oldStory->status == 'draft') continue;

            $story = new stdclass();
            $story->lastEditedBy   = $this->app->user->account;
            $story->lastEditedDate = $now;
            $story->stage          = $stage;

            $this->dao->update(TABLE_STORY)->data($story)->autoCheck()->where('id')->eq((int)$storyID)->exec();
            if(!dao::isError()) $allChanges[$storyID] = common::createChanges($oldStory, $story);
        }
        return $allChanges;
    }

    /**
     * Batch assign to.
     * 
     * @access public
     * @return array
     */
    public function batchAssignTo()
    {
        $now         = helper::now();
        $allChanges  = array();
        $storyIDList = $this->post->storyIDList;
        $assignedTo  = $this->post->assignedTo;
        $oldStories  = $this->getByList($storyIDList);
        foreach($storyIDList as $storyID)
        {
            $oldStory = $oldStories[$storyID];
            if($assignedTo == $oldStory->assignedTo) continue;

            $story = new stdclass();
            $story->lastEditedBy   = $this->app->user->account;
            $story->lastEditedDate = $now;
            $story->assignedTo     = $assignedTo;
            $story->assignedDate   = $now;

            $this->dao->update(TABLE_STORY)->data($story)->autoCheck()->where('id')->eq((int)$storyID)->exec();
            if(!dao::isError()) $allChanges[$storyID] = common::createChanges($oldStory, $story);
        }
        return $allChanges;
    }

    /**
     * Activate a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return bool
     */
    public function activate($storyID)
    {
        $oldStory = $this->dao->findById($storyID)->from(TABLE_STORY)->fetch();
        $now      = helper::now();
        $story = fixer::input('post')
            ->add('lastEditedBy', $this->app->user->account)
            ->add('lastEditedDate', $now)
            ->add('assignedDate', $now)
            ->add('status', 'active') 
            ->add('closedBy', '')
            ->add('closedReason', '')
            ->add('closedDate', '0000-00-00')
            ->add('reviewedBy', '')
            ->add('reviewedDate', '0000-00-00')
            ->remove('comment')
            ->get();
        $this->dao->update(TABLE_STORY)->data($story)->autoCheck()->where('id')->eq($storyID)->exec();
        return true;
    }

    /**
     * Set stage of a story.
     * 
     * @param  int    $storyID 
     * @param  string $customStage 
     * @access public
     * @return bool
     */
    public function setStage($storyID, $customStage = '')
    {
        /* Custom stage defined, use it. */
        if($customStage)
        {
            $this->dao->update(TABLE_STORY)->set('stage')->eq($customStage)->where('id')->eq((int)$storyID)->exec();
            return true;
        }

        /* Get projects which status is doing. */
        $projects = $this->dao->select('project')
            ->from(TABLE_PROJECTSTORY)->alias('t1')->leftJoin(TABLE_PROJECT)->alias('t2')->on('t1.project = t2.id')
            ->where('t1.story')->eq((int)$storyID)
            ->andWhere('t2.status')->ne('done')
            ->andWhere('t2.deleted')->eq(0)
            ->fetchPairs();

        /* If no projects, in plan, stage is planned. No plan, wait. */
        if(!$projects)
        {
            $this->dao->update(TABLE_STORY)->set('stage')->eq('wait')->where('id')->eq((int)$storyID)->andWhere('plan')->eq(0)->exec();
            $this->dao->update(TABLE_STORY)->set('stage')->eq('planned')->where('id')->eq((int)$storyID)->andWhere('plan')->gt(0)->exec();
            return true;
        }

        /* Search related tasks. */
        $tasks = $this->dao->select('type,status')->from(TABLE_TASK)
            ->where('project')->in($projects)
            ->andWhere('story')->eq($storyID)
            ->andWhere('type')->in('devel,test')
            ->andWhere('status')->ne('cancel')
            ->andWhere('deleted')->eq(0)
            ->fetchGroup('type');

        /* No tasks, then the stage is projected. */
        if(!$tasks)
        {
            $this->dao->update(TABLE_STORY)->set('stage')->eq('projected')->where('id')->eq((int)$storyID)->exec();
            return true;
        }

        /* Get current stage and set as default value. */
        $currentStage = $this->dao->findById($storyID)->from(TABLE_STORY)->fields('stage')->fetch('stage');
        $stage = $currentStage;

        /* Cycle all tasks, get counts of every type and every status. */
        $statusList['devel'] = array('wait' => 0, 'doing' => 0, 'done' => 0);
        $statusList['test']  = array('wait' => 0, 'doing' => 0, 'done' => 0);
        foreach($tasks as $type => $typeTasks)
        {
            foreach($typeTasks as $task)
            {
                $status = $task->status ? $task->status : 'wait';
                $status = $status == 'closed' ? 'done' : $status;

                $statusList[$task->type][$status] ++;
            }
        }

        /* Get counts of every type tasks. */
        $develTasks = isset($tasks['devel']) ? count($tasks['devel']) : 0;
        $testTasks  = isset($tasks['test'])  ? count($tasks['test'])  : 0;

        /**
         * Judge stage according to the devel and test tasks' status. 
         * 
         * 1. one doing devel task, all test tasks waiting, set stage as developing.
         * 2. all devel tasks done, all test tasks waiting, set stage as developed.
         * 3. one test task doing, set stage as testing. 
         * 4. all test tasks done, still some devel tasks not done(wait, doing), set stage as testing.
         * 5. all test tasks done, all devel tasks done, set stage as tested.
         */
        if($statusList['devel']['doing'] > 0 and $statusList['test']['wait'] == $testTasks) $stage = 'developing'; 
        if($statusList['devel']['done'] == $develTasks and $develTasks > 0 and $statusList['test']['wait'] == $testTasks) $stage = 'developed';
        if($statusList['test']['doing'] > 0) $stage = 'testing';
        if(($statusList['devel']['wait'] > 0 or $statusList['devel']['doing'] > 0) and $statusList['test']['done'] == $testTasks and $testTasks > 0) $stage = 'testing';
        if($statusList['devel']['done'] == $develTasks and $develTasks > 0 and $statusList['test']['done'] == $testTasks and $testTasks > 0) $stage = 'tested';

        $this->dao->update(TABLE_STORY)->set('stage')->eq($stage)->where('id')->eq((int)$storyID)->exec();
        return;
    }

    /**
     * Get stories list of a product.
     * 
     * @param  int           $productID 
     * @param  array|string  $moduleIds 
     * @param  string        $status 
     * @param  string        $orderBy 
     * @param  object        $pager 
     * @access public
     * @return array
     */
    public function getProductStories($productID = 0, $moduleIds = 0, $status = 'all', $orderBy = 'id_desc', $pager = null)
    {
        return $this->dao->select('t1.*, t2.title as planTitle')
            ->from(TABLE_STORY)->alias('t1')
            ->leftJoin(TABLE_PRODUCTPLAN)->alias('t2')->on('t1.plan = t2.id')
            ->where('t1.product')->in($productID)
            ->beginIF(!empty($moduleIds))->andWhere('module')->in($moduleIds)->fi() 
            ->beginIF($status and $status != 'all')->andWhere('status')->in($status)->fi()
            ->andWhere('t1.deleted')->eq(0)
            ->orderBy($orderBy)->page($pager)->fetchAll();
    }

    /**
     * Get stories pairs of a product.
     * 
     * @param  int           $productID 
     * @param  array|string  $moduleIds 
     * @param  string        $status 
     * @param  string        $order 
     * @param  int           $limit 
     * @access public
     * @return array
     */
    public function getProductStoryPairs($productID = 0, $moduleIds = 0, $status = 'all', $order = 'id_desc', $limit = 0)
    {
        $stories = $this->dao->select('t1.id, t1.title, t1.module, t1.pri, t1.estimate, t2.name AS product')
            ->from(TABLE_STORY)->alias('t1')->leftJoin(TABLE_PRODUCT)->alias('t2')->on('t1.product = t2.id')
            ->where('1=1')
            ->beginIF($productID)->andWhere('t1.product')->in($productID)->fi()
            ->beginIF($moduleIds)->andWhere('t1.module')->in($moduleIds)->fi()
            ->beginIF($status and $status != 'all')->andWhere('t1.status')->in($status)->fi()
            ->andWhere('t1.deleted')->eq(0)
            ->orderBy($order)
            ->fetchAll();
        if(!$stories) return array();
        return $this->formatStories($stories, 'full', $limit);
    }

    /**
     * Get stories by assignedTo.
     * 
     * @param  int    $productID 
     * @param  string $account 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getByAssignedTo($productID, $account, $orderBy, $pager)
    {
        return $this->getByField($productID, 'assignedTo', $account, $orderBy, $pager);
    }

    /**
     * Get stories by openedBy.
     * 
     * @param  int    $productID 
     * @param  string $account 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getByOpenedBy($productID, $account, $orderBy, $pager)
    {
        return $this->getByField($productID, 'openedBy', $account, $orderBy, $pager);
    }

    /**
     * Get stories by reviewedBy.
     * 
     * @param  int    $productID 
     * @param  string $account 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getByReviewedBy($productID, $account, $orderBy, $pager)
    {
        return $this->getByField($productID, 'reviewedBy', $account, $orderBy, $pager, 'include');
    }

    /**
     * Get stories by closedBy.
     * 
     * @param  int    $productID 
     * @param  string $account 
     * @param  string $orderBy 
     * @param  object $pager 
     * @return array
     */
    public function getByClosedBy($productID, $account, $orderBy, $pager)
    {
        return $this->getByField($productID, 'closedBy', $account, $orderBy, $pager);
    }

    /**
     * Get stories by status.
     * 
     * @param  int    $productID 
     * @param  string $orderBy 
     * @param  object $pager 
     * @param  string $status 
     * @access public
     * @return array
     */
    public function getByStatus($productID, $status, $orderBy, $pager)
    {
        return $this->getByField($productID, 'status', $status, $orderBy, $pager);
    }

    /**
     * Get stories by a field.
     * 
     * @param  int    $productID 
     * @param  string $fieldName 
     * @param  mixed  $fieldValue 
     * @param  string $orderBy 
     * @param  object $pager 
     * @param  string $operator     equal|include
     * @access public
     * @return array
     */
    public function getByField($productID, $fieldName, $fieldValue, $orderBy, $pager, $operator = 'equal')
    {
        return $this->dao->select('t1.*, t2.title as planTitle')
            ->from(TABLE_STORY)->alias('t1')
            ->leftJoin(TABLE_PRODUCTPLAN)->alias('t2')->on('t1.plan = t2.id')
            ->where('t1.product')->in($productID)
            ->andWhere('t1.deleted')->eq(0)
            ->beginIF($operator == 'equal')->andWhere($fieldName)->eq($fieldValue)->fi()
            ->beginIF($operator == 'include')->andWhere($fieldName)->like("%$fieldValue%")->fi()
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll();
    }

    /**
     * Get will close stories.
     * 
     * @param  int    $productID 
     * @param  string $orderBy 
     * @param  string $pager 
     * @access public
     * @return array
     */
    public function getWillClose($productID, $orderBy, $pager)
    {
        return $this->dao->select('t1.*, t2.title as planTitle')
            ->from(TABLE_STORY)->alias('t1')
            ->leftJoin(TABLE_PRODUCTPLAN)->alias('t2')->on('t1.plan = t2.id')
            ->where('t1.product')->in($productID)
            ->andWhere('t1.deleted')->eq(0)
            ->andWhere('stage')->in('developed,released')
            ->andWhere('status')->ne('closed')
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll();
    }

    /**
     * Get stories through search.
     * 
     * @access public
     * @param  int    $productID 
     * @param  int    $queryID 
     * @param  string $orderBy 
     * @param  object $pager 
     * @param  string $projectID 
     * @access public
     * @return array
     */
    public function getBySearch($productID, $queryID, $orderBy, $pager = null, $projectID = '')
    {
        if($projectID != '') 
        {
            $products = $this->loadModel('project')->getProducts($projectID); 
        }
        else
        {
            $products = $this->loadModel('product')->getPairs();
        }
        $query = $queryID ? $this->loadModel('search')->getQuery($queryID) : '';

        /* Get the sql and form status from the query. */
        if($query)
        {
            $this->session->set('storyQuery', $query->sql);
            $this->session->set('storyForm', $query->form);
        }
        if($this->session->storyQuery == false) $this->session->set('storyQuery', ' 1 = 1');

        $allProduct     = "`product` = 'all'";
        $storyQuery     = $this->session->storyQuery;
        $queryProductID = $productID;
        if(strpos($this->session->storyQuery, $allProduct) !== false)
        {
            $storyQuery     = str_replace($allProduct, '1', $this->session->storyQuery);
            $queryProductID = 'all';
        }
        $storyQuery = $storyQuery . ' AND `product`' . helper::dbIN(array_keys($products));
        if($projectID != '') $storyQuery .= " AND `status` != 'draft'"; 

        return $this->getBySQL($queryProductID, $storyQuery, $orderBy, $pager);
    }

    /**
     * Get stories by a sql.
     * 
     * @param  int    $productID 
     * @param  string $sql 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getBySQL($productID, $sql, $orderBy, $pager = null)
    {
        $productIDs = array_keys($this->loadModel('product')->getPrivProducts());

        /* Get plans. */
        $plans = $this->dao->select('id,title')->from(TABLE_PRODUCTPLAN)
            ->beginIF($productID != 'all' and $productID != '')->where('product')->eq((int)$productID)->fi()
            ->beginIF($productID == 'all')->where('product')->in($productIDs)->fi()
            ->fetchPairs();

        $tmpStories = $this->dao->select('*')->from(TABLE_STORY)->where($sql)
            ->beginIF($productID != 'all' and $productID != '')->andWhere('product')->eq((int)$productID)->fi()
            ->beginIF($productID == 'all')->andWhere('product')->in($productIDs)->fi()
            ->andWhere('deleted')->eq(0)
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll('id');

        if(!$tmpStories) return array();

        /* Process plans. */
        $stories = array();
        foreach($tmpStories as $story)
        {
            $story->planTitle = isset($plans[$story->plan]) ? $plans[$story->plan] : '';
            $stories[] = $story;
        }
        return $stories;
    }
    
    /**
     * Get stories list of a project.
     * 
     * @param  int    $projectID 
     * @param  string $orderBy 
     * @access public
     * @return array
     */
    public function getProjectStories($projectID = 0, $orderBy = 'pri_asc,id_desc', $type = 'byModule', $param = 0)
    {
        $modules = ($type == 'byModule' and $param) ? $this->dao->select('*')->from(TABLE_MODULE)->where('path')->like("%,$param,%")->andWhere('type')->eq('story')->fetchPairs('id', 'id') : array();
        $stories = $this->dao->select('t1.*, t2.*')->from(TABLE_PROJECTSTORY)->alias('t1')
            ->leftJoin(TABLE_STORY)->alias('t2')->on('t1.story = t2.id')
            ->where('t1.project')->eq((int)$projectID)
            ->beginIF($type == 'byProduct')->andWhere('t1.product')->eq($param)->fi()
            ->beginIF($type == 'byModule' and $param)->andWhere('t2.module')->in($modules)->fi()
            ->andWhere('t2.deleted')->eq(0)
            ->orderBy($orderBy)
            ->fetchAll('id');
        return $stories;
    }

    /**
     * Get stories pairs of a project.
     * 
     * @param  int           $projectID 
     * @param  int           $productID 
     * @param  array|string  $moduleIds 
     * @param  string        $type
     * @access public
     * @return array
     */
    public function getProjectStoryPairs($projectID = 0, $productID = 0, $moduleIds = 0, $type = 'full')
    {
        $stories = $this->dao->select('t2.id, t2.title, t2.module, t2.pri, t2.estimate, t3.name AS product')
            ->from(TABLE_PROJECTSTORY)->alias('t1')
            ->leftJoin(TABLE_STORY)->alias('t2')
            ->on('t1.story = t2.id')
            ->leftJoin(TABLE_PRODUCT)->alias('t3')
            ->on('t1.product = t3.id')
            ->where('t1.project')->eq((int)$projectID)
            ->andWhere('t2.deleted')->eq(0)
            ->beginIF($productID)->andWhere('t1.product')->eq((int)$productID)->fi()
            ->beginIF($moduleIds)->andWhere('t2.module')->in($moduleIds)->fi()
            ->fetchAll();
        if(!$stories) return array();
        return $this->formatStories($stories, $type);
    }

    /**
     * Get stories list of a plan.
     * 
     * @param  int    $planID 
     * @param  string $status 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getPlanStories($planID, $status = 'all', $orderBy = 'id_desc', $pager = null)
    {
        $stories = $this->dao->select('*')->from(TABLE_STORY)
            ->where('plan')->eq((int)$planID)
            ->beginIF($status and $status != 'all')->andWhere('status')->in($status)->fi()
            ->andWhere('deleted')->eq(0)
            ->orderBy($orderBy)->page($pager)->fetchAll('id');
        
        $this->loadModel('common')->saveQueryCondition($this->dao->get(), 'story');
        
        return $stories;
    }

    /**
     * Get stories pairs of a plan.
     * 
     * @param  int    $planID 
     * @param  string $status 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getPlanStoryPairs($planID, $status = 'all', $orderBy = 'id_desc', $pager = null)
    {
        return $this->dao->select('*')->from(TABLE_STORY)
            ->where('plan')->eq($planID)
            ->beginIF($status and $status != 'all')->andWhere('status')->in($status)->fi()
            ->andWhere('deleted')->eq(0)
            ->fetchAll();
    }

    /**
     * Get stories of a user.
     * 
     * @param  string $account 
     * @param  string $type         the query type 
     * @param  string $orderBy 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getUserStories($account, $type = 'assignedTo', $orderBy = 'id_desc', $pager = null)
    {
        $stories = $this->dao->select('t1.*, t2.title as planTitle, t3.name as productTitle')
            ->from(TABLE_STORY)->alias('t1')
            ->leftJoin(TABLE_PRODUCTPLAN)->alias('t2')->on('t1.plan = t2.id')
            ->leftJoin(TABLE_PRODUCT)->alias('t3')->on('t1.product = t3.id')
            ->where('t1.deleted')->eq(0)
            ->beginIF($type != 'all')
            ->beginIF($type == 'assignedTo')->andWhere('assignedTo')->eq($account)->fi()
            ->beginIF($type == 'openedBy')->andWhere('openedBy')->eq($account)->fi()
            ->beginIF($type == 'reviewedBy')->andWhere('reviewedBy')->like('%' . $account . '%')->fi()
            ->beginIF($type == 'closedBy')->andWhere('closedBy')->eq($account)->fi()
            ->fi()
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll();
        
        $this->loadModel('common')->saveQueryCondition($this->dao->get(), 'story');
        
        return $stories;
    }

    /**
     * Get story pairs of a user.
     * 
     * @param  string    $account 
     * @param  string    $limit 
     * @access public
     * @return array
     */
    public function getUserStoryPairs($account, $limit = 10)
    {
        return $this->dao->select('id, title')
            ->from(TABLE_STORY)
            ->where('deleted')->eq(0)
            ->andWhere('assignedTo')->eq($account)
            ->orderBy('id_desc')
            ->limit($limit)
            ->fetchAll();
    }
    
    /**
     * Get doing projects' members of a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return array
     */
    public function getProjectMembers($storyID)
    {
        $projects = $this->dao->select('project')
            ->from(TABLE_PROJECTSTORY)->alias('t1')->leftJoin(TABLE_PROJECT)->alias('t2')->on('t1.project = t2.id')
            ->where('t1.story')->eq((int)$storyID)
            ->andWhere('t2.status')->eq('doing')
            ->andWhere('t2.deleted')->eq(0)
            ->fetchPairs();
        if($projects) return($this->dao->select('account')->from(TABLE_TEAM)->where('project')->in($projects)->fetchPairs('account'));
    }

    /**
     * Get version of a story.
     * 
     * @param  int    $storyID 
     * @access public
     * @return int
     */
    public function getVersion($storyID)
    {
        return $this->dao->select('version')->from(TABLE_STORY)->where('id')->eq((int)$storyID)->fetch('version');
    }

    /**
     * Get versions of some stories.
     * 
     * @param  array|string story id list
     * @access public
     * @return array
     */
    public function getVersions($storyID)
    {
        return $this->dao->select('id, version')->from(TABLE_STORY)->where('id')->in($storyID)->fetchPairs();
    }

    /**
     * Get zero case.
     * 
     * @param  int    $productID 
     * @access public
     * @return array
     */
    public function getZeroCase($productID, $orderBy = 'id_desc')
    {
        $allStories   = $this->getProductStories($productID, 0, 'all', $orderBy);
        $casedStories = $this->dao->select('DISTINCT story')->from(TABLE_CASE)->where('product')->eq($productID)->andWhere('story')->ne(0)->andWhere('deleted')->eq(0)->fetchAll('story');

        foreach($allStories as $key => $story)
        {
            if(isset($casedStories[$story->id])) unset($allStories[$key]);
        }

        return $allStories;
    }

    /**
     * Format stories 
     * 
     * @param  array    $stories 
     * @param  string   $type
     * @param  int      $limit
     * @access public
     * @return void
     */
    public function formatStories($stories, $type = 'full', $limit = 0)
    {
        /* Get module names of stories. */
        /*$modules = array();
        foreach($stories as $story) $modules[] = $story->module;
        $moduleNames = $this->dao->select('id, name')->from(TABLE_MODULE)->where('id')->in($modules)->fetchPairs();*/

        /* Format these stories. */
        $storyPairs = array('' => '');
        $i = 0;
        foreach($stories as $story)
        {
            if($type == 'short')
            {
                $property = '[p' . $story->pri . ', ' . $story->estimate . 'h]';
            }
            else
            {
                $property = '(' . $this->lang->story->pri . ':' . $story->pri . ',' . $this->lang->story->estimate . ':' . $story->estimate . ')';
            }
            $storyPairs[$story->id] = $story->id . ':' . $story->title . $property;

            if($limit > 0 && ++$i > $limit)
            {
                $storyPairs['showmore'] = $this->lang->more . $this->lang->ellipsis;
                break;
            }
        }
        return $storyPairs;
    }

    /**
     * Extract accounts from some stories.
     * 
     * @param  array  $stories 
     * @access public
     * @return array
     */
    public function extractAccountsFromList($stories)
    {
        $accounts = array();
        foreach($stories as $story)
        {
            if(!empty($story->openedBy))     $accounts[] = $story->openedBy;
            if(!empty($story->assignedTo))   $accounts[] = $story->assignedTo;
            if(!empty($story->closedBy))     $accounts[] = $story->closedBy;
            if(!empty($story->lastEditedBy)) $accounts[] = $story->lastEditedBy;
        }
        return array_unique($accounts);
    }

    /**
     * Extract accounts from a story.
     * 
     * @param  object  $story 
     * @access public
     * @return array
     */
    public function extractAccountsFromSingle($story)
    {
        $accounts = array();
        if(!empty($story->openedBy))     $accounts[] = $story->openedBy;
        if(!empty($story->assignedTo))   $accounts[] = $story->assignedTo;
        if(!empty($story->closedBy))     $accounts[] = $story->closedBy;
        if(!empty($story->lastEditedBy)) $accounts[] = $story->lastEditedBy;
        return array_unique($accounts);
    }

    /**
     * Merge the default chart settings and the settings of current chart.
     * 
     * @param  string    $chartType 
     * @access public
     * @return void
     */
    public function mergeChartOption($chartType)
    {
        $chartOption  = $this->lang->story->report->$chartType;
        $commonOption = $this->lang->story->report->options;

        $chartOption->graph->caption = $this->lang->story->report->charts[$chartType];
        if(!isset($chartOption->type))    $chartOption->type    = $commonOption->type;
        if(!isset($chartOption->width))  $chartOption->width  = $commonOption->width;
        if(!isset($chartOption->height)) $chartOption->height = $commonOption->height;

        foreach($commonOption->graph as $key => $value) if(!isset($chartOption->graph->$key)) $chartOption->graph->$key = $value;
    }

    /**
     * Get report data of storys per product 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerProduct()
    {
        $datas = $this->dao->select('product as name, count(product) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('product')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        $products = $this->loadModel('product')->getPairs();
        foreach($datas as $productID => $data) $data->name = isset($products[$productID]) ? $products[$productID] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per module 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerModule()
    {
        $datas = $this->dao->select('module as name, count(module) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('module')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        $modules = $this->dao->select('id, name')->from(TABLE_MODULE)->where('id')->in(array_keys($datas))->fetchPairs();
        foreach($datas as $moduleID => $data) $data->name = isset($modules[$moduleID]) ? $modules[$moduleID] : '/';
        return $datas;
    }

    /**
     * Get report data of storys per source 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerSource()
    {
        $datas = $this->dao->select('source as name, count(source) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('source')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        $this->lang->story->sourceList[''] = $this->lang->report->undefined;
        foreach($datas as $key => $data) $data->name = isset($this->lang->story->sourceList[$key]) ? $this->lang->story->sourceList[$key] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per plan 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerPlan()
    {
        $datas = $this->dao->select('plan as name, count(plan) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('plan')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        $plans = $this->dao->select('id, title')->from(TABLE_PRODUCTPLAN)->where('id')->in(array_keys($datas))->fetchPairs();
        foreach($datas as $planID => $data) $data->name = isset($plans[$planID]) ? $plans[$planID] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per status 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerStatus()
    {
        $datas = $this->dao->select('status as name, count(status) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('status')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        foreach($datas as $status => $data) if(isset($this->lang->story->statusList[$status])) $data->name = $this->lang->story->statusList[$status];
        return $datas;
    }

    /**
     * Get report data of storys per stage 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerStage()
    {
        $datas = $this->dao->select('stage as name, count(stage) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('stage')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        foreach($datas as $stage => $data) $data->name = $this->lang->story->stageList[$stage] != '' ? $this->lang->story->stageList[$stage] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per pri 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerPri()
    {
        $datas = $this->dao->select('pri as name, count(pri) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('pri')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        foreach($datas as $pri => $data)  $data->name = $this->lang->story->priList[$pri] != '' ? $this->lang->story->priList[$pri] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per estimate 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerEstimate()
    {
        return $this->dao->select('estimate as name, count(estimate) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('estimate')->orderBy('value')->fetchAll();
    }

    /**
     * Get report data of storys per openedBy 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerOpenedBy()
    {
        $datas = $this->dao->select('openedBy as name, count(openedBy) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('openedBy')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        if(!isset($this->users)) $this->users = $this->loadModel('user')->getPairs('noletter');
        foreach($datas as $account => $data) $data->name = isset($this->users[$account]) ? $this->users[$account] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per assignedTo 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerAssignedTo()
    {
        $datas = $this->dao->select('assignedTo as name, count(assignedTo) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('assignedTo')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        if(!isset($this->users)) $this->users = $this->loadModel('user')->getPairs('noletter');
        foreach($datas as $account => $data) $data->name = (isset($this->users[$account]) and $this->users[$account] != '') ? $this->users[$account] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per closedReason 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerClosedReason()
    {
        $datas = $this->dao->select('closedReason as name, count(closedReason) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('closedReason')->orderBy('value DESC')->fetchAll('name');
        if(!$datas) return array();
        foreach($datas as $reason => $data) $data->name = $this->lang->story->reasonList[$reason] != '' ? $this->lang->story->reasonList[$reason] : $this->lang->report->undefined;
        return $datas;
    }

    /**
     * Get report data of storys per change 
     * 
     * @access public
     * @return array
     */
    public function getDataOfStorysPerChange()
    {
        return $this->dao->select('(version-1) as name, count(*) as value')->from(TABLE_STORY)
            ->beginIF($this->session->storyQueryCondition !=  false)->where($this->session->storyQueryCondition)->fi()
            ->groupBy('version')->orderBy('value')->fetchAll();
    }

    /**
     * Adjust the action clickable.
     * 
     * @param  object $story 
     * @param  string $action 
     * @access public
     * @return void
     */
    public static function isClickable($story, $action)
    {
        $action = strtolower($action);

        if($action == 'change')   return $story->status != 'closed';
        if($action == 'review')   return $story->status == 'draft' or $story->status == 'changed';
        if($action == 'close')    return $story->status != 'closed';
        if($action == 'activate') return $story->status == 'closed';

        return true;
    }
}
