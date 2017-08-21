<?php if (!defined ('BASEPATH')) exit ('No direct script access allowed');

/**
 * @author      OA Wu <comdan66@gmail.com>
 * @copyright   Copyright (c) 2017 OA Wu Design
 * @license     http://creativecommons.org/licenses/by-nc/2.0/tw/
 */

class Albums extends Admin_controller {
  private $uri_1 = null;
  private $obj = null;
  private $icon = null;
  private $title = null;

  public function __construct () {
    parent::__construct ();
    
    if (!User::current ()->in_roles (array ('member')))
      return redirect_message (array ('admin'), array ('_fd' => '您的權限不足，或者頁面不存在。'));
    
    $this->uri_1 = 'admin/albums';
    $this->icon = 'icon-images';
    $this->title = '相簿';

    if (in_array ($this->uri->rsegments (2, 0), array ('edit', 'update', 'destroy', 'status', 'show')))
      if (!(($id = $this->uri->rsegments (3, 0)) && ($this->obj = Article::find ('one', array ('conditions' => array ('id = ? AND status != ? AND type = ?', $id, Article::STATUS_1, Article::TYPE_5))))))
        return redirect_message (array ($this->uri_1), array ('_fd' => '找不到該筆資料。'));

    $this->add_param ('uri_1', $this->uri_1)
         ->add_param ('icon', $this->icon)
         ->add_param ('title', $this->title)
         ->add_param ('_url', base_url ($this->uri_1));

    if (in_array ($this->uri->rsegments (2, 0), array ('create', 'update')))
      error_reporting (E_ALL & ~E_NOTICE & ~E_WARNING);
  }
  public function index ($offset = 0) {
    $searches = array (
        'status'    => array ('el' => 'select', 'text' => '是否上架', 'sql' => 'status = ?', 'items' => array_map (function ($t) { return array ('text' => Article::$statusNames[$t], 'value' => $t,);}, array_keys (Article::$statusNames))),
        'title'     => array ('el' => 'input', 'text' => '標題', 'sql' => 'title LIKE ?'),
        'content'   => array ('el' => 'input', 'text' => '內容', 'sql' => 'content LIKE ?'),
      );

    $configs = array_merge (explode ('/', $this->uri_1), array ('%s'));
    $objs = conditions ($searches, $configs, $offset, 'Article', array ('order' => 'id DESC', 'include' => array ('images')), function ($conditions) {
      OaModel::addConditions ($conditions, 'status != ? AND type = ?', Article::STATUS_1, Article::TYPE_5);
      return $conditions;
    });

    return $this->load_view (array (
        'objs' => $objs,
        'total' => $offset,
        'searches' => $searches,
        'pagination' => $this->_get_pagination ($configs),
      ));
  }
  public function add () {
    $posts = Session::getData ('posts', true);
    $tag_ids = isset ($posts['tag_ids']) ? $posts['tag_ids'] : array ();

    return $this->load_view (array (
        'posts' => $posts,
        'tag_ids' => $tag_ids,
      ));
  }
  public function create () {
    if (!$this->has_post ())
      return redirect_message (array ($this->uri_1, 'add'), array ('_fd' => '非 POST 方法，錯誤的頁面請求。'));

    $posts = OAInput::post ();
    $posts['type'] = Article::TYPE_5;
    $posts['content'] = OAInput::post ('content', false);
    $cover = OAInput::file ('cover');
    $images = OAInput::file ('images[]');

    $validation = function (&$posts, &$cover, &$images) {
      if (!(isset ($posts['status']) && is_string ($posts['status']) && is_numeric ($posts['status'] = trim ($posts['status'])) && in_array ($posts['status'], array_keys (Article::$statusNames)))) $posts['status'] = Article::STATUS_2;
      if (!(isset ($posts['title']) && is_string ($posts['title']) && ($posts['title'] = trim ($posts['title'])))) return '「' . $this->title . '標題」格式錯誤！';
      if (!(isset ($posts['bio']) && is_string ($posts['bio']) && ($posts['bio'] = trim ($posts['bio'])))) return '「' . $this->title . '副標題」格式錯誤！';
      if (!(isset ($posts['date_at']) && is_string ($posts['date_at']) && is_date ($posts['date_at'] = trim ($posts['date_at'])))) return '「' . $this->title . '時間」格式錯誤！';

      if (!(isset ($cover) && is_upload_image_format ($cover, array ('gif', 'jpeg', 'jpg', 'png')))) return '「' . $this->title . '封面」格式錯誤！';
      if (!(isset ($posts['content']) && is_string ($posts['content']) && ($posts['content'] = trim ($posts['content'])))) return '「' . $this->title . '內容」格式錯誤！';

      $posts['tag_ids'] = isset ($posts['tag_ids']) && is_array ($posts['tag_ids']) && $posts['tag_ids'] ? column_array (Tag::find ('all', array ('select' => 'id', 'conditions' => array ('id IN (?)', $posts['tag_ids']))), 'id') : array ();
      $images = array_values (array_filter ($images, function ($image) { return is_upload_image_format ($image, array ('gif', 'jpeg', 'jpg', 'png')); }));
      
      return '';
    };

    if (($msg = $validation ($posts, $cover, $images)) || (!(Article::transaction (function () use (&$obj, $posts, $cover) { if (!verifyCreateOrm ($obj = Article::create (array_intersect_key ($posts, Article::table ()->columns)))) return false; return $obj->cover->put ($cover); }) && $obj) && ($msg = '資料庫處理錯誤！')))
      return redirect_message (array ($this->uri_1, 'add'), array ('_fd' => $msg, 'posts' => $posts));

    if ($posts['tag_ids'])
      foreach ($posts['tag_ids'] as $tag_id)
        ArticleTagMapping::transaction (function () use ($tag_id, $obj) { return verifyCreateOrm (ArticleTagMapping::create (array_intersect_key (array ('tag_id' => $tag_id, 'article_id' => $obj->id), ArticleTagMapping::table ()->columns))); });

    if ($images)
      foreach ($images as $image)
        ArticleImage::transaction (function () use ($image, $obj) { return verifyCreateOrm ($img = ArticleImage::create (array_intersect_key (array ('article_id' => $obj->id, 'title' => ''), ArticleImage::table ()->columns))) && $img->name->put ($image); });

    return redirect_message (array ($this->uri_1), array ('_fi' => '新增成功！'));
  }
  public function edit () {
    $posts = Session::getData ('posts', true);
    $tag_ids = isset ($posts['tag_ids']) ? $posts['tag_ids'] : column_array ($this->obj->mappings, 'tag_id');
    
    return $this->load_view (array (
        'posts' => $posts,
        'obj' => $this->obj,
        'tag_ids' => $tag_ids,
      ));
  }
  public function update () {
    $obj = $this->obj;

    if (!$this->has_post ())
      return redirect_message (array ($this->uri_1, $obj->id, 'edit'), array ('_fd' => '非 POST 方法，錯誤的頁面請求。'));

    $posts = OAInput::post ();
    $posts['content'] = OAInput::post ('content', false);
    $cover = OAInput::file ('cover');
    $images = OAInput::file ('images[]');

    $validation = function (&$posts, &$cover, &$images, $obj) {
      if (isset ($posts['status']) && !(is_string ($posts['status']) && is_numeric ($posts['status'] = trim ($posts['status'])) && in_array ($posts['status'], array_keys (Article::$statusNames)))) $posts['status'] = Article::STATUS_2;
      if (isset ($posts['title']) && !(is_string ($posts['title']) && ($posts['title'] = trim ($posts['title'])))) return '「' . $this->title . '標題」格式錯誤！';
      if (isset ($posts['bio']) && !(is_string ($posts['bio']) && ($posts['bio'] = trim ($posts['bio'])))) return '「' . $this->title . '副標題」格式錯誤！';
      if (isset ($posts['date_at']) && !(is_string ($posts['date_at']) && is_date ($posts['date_at'] = trim ($posts['date_at'])))) return '「' . $this->title . '時間」格式錯誤！';

      if (!((string)$obj->cover || isset ($cover))) return '「' . $this->title . '封面」格式錯誤！';
      if (isset ($cover) && !(is_upload_image_format ($cover, array ('gif', 'jpeg', 'jpg', 'png')))) return '「' . $this->title . '封面」格式錯誤！';
      if (isset ($posts['content']) && !(is_string ($posts['content']) && ($posts['content'] = trim ($posts['content'])))) return '「' . $this->title . '內容」格式錯誤！';

      $posts['tag_ids'] = isset ($posts['tag_ids']) && is_array ($posts['tag_ids']) && $posts['tag_ids'] ? column_array (Tag::find ('all', array ('select' => 'id', 'conditions' => array ('id IN (?)', $posts['tag_ids']))), 'id') : array ();
      $images = array_values (array_filter ($images, function ($image) { return is_upload_image_format ($image, array ('gif', 'jpeg', 'jpg', 'png')); }));

      return '';
    };

    if ($msg = $validation ($posts, $cover, $images, $obj))
      return redirect_message (array ($this->uri_1, $obj->id, 'edit'), array ('_fd' => $msg, 'posts' => $posts));

    if ($columns = array_intersect_key ($posts, $obj->table ()->columns))
      foreach ($columns as $column => $value)
        $obj->$column = $value;

    if (!Article::transaction (function () use ($obj, $posts, $cover) { if (!$obj->save ()) return false; if ($cover && !$obj->cover->put ($cover)) return false; return true; }))
      return redirect_message (array ($this->uri_1, $obj->id, 'edit'), array ('_fd' => '資料庫處理錯誤！', 'posts' => $posts));

    $ori_ids = column_array ($obj->mappings, 'tag_id');

    if (($del_ids = array_diff ($ori_ids, $posts['tag_ids'])) && ($mappings = ArticleTagMapping::find ('all', array ('select' => 'id, tag_id', 'conditions' => array ('article_id = ? AND tag_id IN (?)', $obj->id, $del_ids)))))
      foreach ($mappings as $mapping)
        ArticleTagMapping::transaction (function () use ($mapping) { return $mapping->destroy (); });

    if (($add_ids = array_diff ($posts['tag_ids'], $ori_ids)) && ($tags = Tag::find ('all', array ('select' => 'id', 'conditions' => array ('id IN (?)', $add_ids)))))
      foreach ($tags as $tag)
        ArticleTagMapping::transaction (function () use ($tag, $obj) { return verifyCreateOrm (ArticleTagMapping::create (Array_intersect_key (array ('tag_id' => $tag->id, 'article_id' => $obj->id), ArticleTagMapping::table ()->columns))); });

    if (($del_ids = array_diff (column_array ($obj->images, 'id'), $posts['oldimg'])) && ($imgs = ArticleImage::find ('all', array ('select' => 'id, name', 'conditions' => array ('id IN (?)', $del_ids)))))
      foreach ($imgs as $img)
        ArticleImage::transaction (function () use ($img) { return $img->destroy (); });

    if ($images)
      foreach ($images as $image)
        ArticleImage::transaction (function () use ($image, $obj) { return verifyCreateOrm ($img = ArticleImage::create (array_intersect_key (array ('article_id' => $obj->id, 'title' => ''), ArticleImage::table ()->columns))) && $img->name->put ($image); });

    return redirect_message (array ($this->uri_1), array ('_fi' => '更新成功！'));
  }
  public function destroy () {
    $obj = $this->obj;

    if (!Article::transaction (function () use ($obj) { return $obj->destroy (); }))
      return redirect_message (array ($this->uri_1), array ('_fd' => '資料庫處理錯誤！'));

    return redirect_message (array ($this->uri_1), array ('_fi' => '刪除成功！'));
  }
  public function status () {
    $obj = $this->obj;

    if (!$this->has_post ())
      return $this->output_error_json ('非 POST 方法，錯誤的頁面請求。');

    $posts = OAInput::post ();

    $validation = function (&$posts) {
      return !(isset ($posts['status']) && is_string ($posts['status']) && is_numeric ($posts['status'] = trim ($posts['status'])) && ($posts['status'] = $posts['status'] ? Article::STATUS_3 : Article::STATUS_2) && in_array ($posts['status'], array_keys (Article::$statusNames))) ? '「設定上下架」發生錯誤！' : '';
    };

    if ($msg = $validation ($posts))
      return $this->output_error_json ($msg);

    if ($columns = array_intersect_key ($posts, $obj->table ()->columns))
      foreach ($columns as $column => $value)
        $obj->$column = $value;

    if (!Article::transaction (function () use ($obj) { return $obj->save (); }))
      return $this->output_error_json ('資料庫處理錯誤！');

    return $this->output_json ($obj->status == Article::STATUS_3);
  }
}
