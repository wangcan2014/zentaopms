<?php
/**
 * The story view file of dashboard module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2013 青岛易软天创网络科技有限公司 (QingDao Nature Easy Soft Network Technology Co,LTD www.cnezsoft.com)
 * @license     LGPL (http://www.gnu.org/licenses/lgpl.html)
 * @author      Yidong Wang <yidong@cnezsoft.com>
 * @package     my
 * @version     $Id: story.html.php 4735 2013-05-03 08:30:02Z chencongzhi520@gmail.com $
 * @link        http://www.zentao.net
 */
?>
<?php include '../../common/view/m.header.html.php';?>
  <div data-role='navbar' id='subMenu'>
    <ul>
      <?php foreach($config->mobile->storyBar as $menu):?>
      <?php $active = $type == $menu ? 'ui-btn-active' : ''?>
      <li><?php echo html::a($this->createLink('my', 'story', "type=$menu"), $lang->my->storyMenu->{$menu . 'Me'}, '', "class='$active' data-theme='d'")?></li>
      <?php endforeach;?>
    </ul>
  </div>
</div>
<?php foreach($stories as $story):?>
<div  data-role="collapsible-set">
  <div data-role="collapsible" data-collapsed="true">
    <h1><?php echo $story->title;?></h1>
    <div>
      <p><strong><?php echo $lang->story->spec?><strong></p>
      <?php echo $story->spec?>
    </div>
    <div>
      <p><strong><?php echo $lang->story->verify?><strong></p>
      <?php echo $story->verify?>
    </div>
    <div data-role='navbar'>
      <ul>
        <?php
        if(!$story->deleted)
        {
            common::printIcon('story', 'review',     "storyID=$story->id", $story);
            common::printIcon('story', 'close',      "storyID=$story->id", $story, '', '', '', 'iframe', true);
            common::printIcon('story', 'activate',   "storyID=$story->id", $story, '', '', '', 'iframe', true);
            common::printIcon('story', 'delete', "storyID=$story->id", '', '', '', 'hiddenwin');
        }
        ?>
      </ul>
    </div>
  </div>
</div>
<?php endforeach;?>
<p><?php $pager->show('right', 'short')?></p>
<?php include '../../common/view/m.footer.html.php';?>