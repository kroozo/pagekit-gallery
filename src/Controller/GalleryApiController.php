<?php

namespace Shw\Gallery\Controller;

use Pagekit\Application as App;
use Shw\Gallery\Model\Gallery;
use Shw\Gallery\Model\Image;
use Gregwar\Image\Image as GImage;

/**
 * @Access("gallery: manage own galleries || gallery: manage all galleries")
 * @Route("/", name="gallery")
 */
class GalleryApiController
{
    /**
     * @Route("/", methods="GET")
     * @Request({"filter": "array", "page":"int"})
     */
    public function indexAction($filter = [], $page = 0)
    {
        $query  = Gallery::query();
        $filter = array_merge(array_fill_keys(['photograph', 'status', 'search', 'author', 'order', 'limit'], ''), $filter);

        extract($filter, EXTR_SKIP);

        if(!App::user()->hasAccess('gallery: manage all galleries')) {
            $author = App::user()->id;
        }

        if (is_numeric($status)) {
            $query->where(['status' => (int) $status]);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere(['title LIKE :search', 'slug LIKE :search'], ['search' => "%{$search}%"]);
            });
        }

        if ($author) {
            $query->where(function ($query) use ($author) {
                $query->orWhere(['user_id' => (int) $author]);
            });
        }

        if (!preg_match('/^(date|title)\s(asc|desc)$/i', $order, $order)) {
            $order = [1 => 'date', 2 => 'desc'];
        }

        $limit = 10; //TODO add limit to settings (int) $limit ?: App::module('gallery')->config('gallery.galleries_per_page');
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page  = max(0, min($pages - 1, $page));

        $galleries = array_values($query->offset($page * $limit)->related('user')->limit($limit)->orderBy($order[1], $order[2])->get());

        return compact('galleries', 'pages', 'count');
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        return Gallery::query()->related('user', 'images')->where(compact('id'))->first();
    }

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"gallery": "array", "id": "int"}, csrf=true)
     */
    public function saveAction($data, $id = 0)
    {
        if (!$id || !$gallery = Gallery::find($id)) {

            if ($id) {
                App::abort(404, __('Gallery not found.'));
            }

            $gallery = Gallery::create();
        }

        if (!$data['slug'] = App::filter($data['slug'] ?: $data['title'], 'slugify')) {
            App::abort(400, __('Invalid slug'));
        }

        // user without universal access is not allowed to assign galleries to other users
        if(!App::user()->hasAccess('gallery: manage all galleries')) {
            $data['user_id'] = App::user()->id;
        }

        // user without universal access can only edit their own galleries
        if(!App::user()->hasAccess('gallery: manage all galleries') && !App::user()->hasAccess('gallery: manage own galleries') && $gallery->user_id !== App::user()->id) {
            App::abort(400, __('Access denied'));
        }

        $gallery->save($data);

        return ['message' => 'success', 'gallery' => $gallery];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     * @Request({"id": "int"}, csrf=true)
     */
    public function deleteAction($id)
    {
        if ($gallery = Gallery::find($id)) {

            if(!App::user()->hasAccess('gallery: manage all galleries') && !App::user()->hasAccess('gallery: manage own galleries') && $gallery->user_id !== App::user()->id) {
                App::abort(400, __('Access denied.'));
            }

            //delete pictures
            $images = Image::query()->where(['gallery_id' => $gallery->id])->get();

            foreach($images as $image) {
                unlink('storage/shw-gallery/'.$image->filename);
                unlink('storage/shw-gallery/thumbnails/tn_'.$image->filename);
                $image->delete();
            }

            $gallery->delete();
        }

        return ['message' => 'success'];
    }

    /**
     * @Route(methods="POST")
     * @Request({"ids": "int[]"}, csrf=true)
     */
    public function copyAction($ids = [])
    {
        foreach ($ids as $id) {
            if ($gallery = Gallery::find((int) $id)) {
                if(!App::user()->hasAccess('gallery: manage all galleries') && !App::user()->hasAccess('gallery: manage own galleries') && $gallery->user_id !== App::user()->id) {
                    continue;
                }

                $gallery = clone $gallery;
                $gallery->id = null;
                $gallery->status = Gallery::STATUS_DRAFT;
                $gallery->title = $gallery->title.' - '.__('Copy');
                $gallery->comment_count = 0;
                $gallery->date = new \DateTime();
                $gallery->save();
            }
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="POST")
     * @Request({"galleries": "array"}, csrf=true)
     */
    public function bulkSaveAction($galleries = [])
    {
        foreach ($galleries as $data) {
            $this->saveAction($data, isset($data['id']) ? $data['id'] : 0);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="DELETE")
     * @Request({"ids": "array"}, csrf=true)
     */
    public function bulkDeleteAction($ids = [])
    {
        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/upload", methods="POST", requirements={"id"="\d+"})
     * @Request({"id": "int"}, csrf=true)
     */
    public function uploadAction($id)
    {
        if (!$id || !$gallery = Gallery::find($id)) {
            App::abort(404, __('Gallery not found.'));
        }

        $path = 'storage/shw-gallery';

        if(!file_exists($path)) {
            mkdir($path, 0755);
            mkdir($path.'/thumbnails', 0755);
        }

        $images = [];
        $files = self::rearrange($_FILES['images']);

        foreach($files as $file) {

            //preg_match('/\.([0-9a-z]+)(?=[?#])|(\.)(?:[\w]+)$/', $file['name'], $match);
            preg_match('/^.*\.(jpg|jpeg|png)$/i', $file['name'], $match);

            if(!key_exists(0, $match)) {
                App::abort(400, 'Only JPG / PNG is supported');
            }

            $file['name'] = str_replace($match[0], '', $file['name']);
            $new_filename = strtolower(time()."_".App::filter($file['name'], 'slugify').$match[0]);

            $img = GImage::open($file['tmp_name']);

            $img->cropResize(
                App::module('gallery')->config('images.image_width'),
                App::module('gallery')->config('images.image_height'))
                ->save($path."/".$new_filename, App::module('gallery')->config('images.image_quality'));

            $img->zoomCrop(
                App::module('gallery')->config('images.thumbnail_width'),
                App::module('gallery')->config('images.thumbnail_height'))
                ->save($path."/thumbnails/tn_".$new_filename, App::module('gallery')->config('images.image_quality'));

            $image = Image::create();
            $image->gallery_id = $gallery->id;
            $image->user_id = App::user()->id;
            $image->filename = $new_filename;
            $image->modified = new \DateTime();
            $image->save();
        }

        $images = Image::query()->where(['gallery_id' => $gallery->id])->get();

        return ['message' => 'success', 'images' => $images];
    }

    /**
     * @Route("/dashboard", methods="GET")
     * @Request({"filter": "array"})
     */
    public function dashboardAction($filter = [])
    {
        $query = Gallery::query();

        if (key_exists('status', $filter)) {
            $query->where(['status' => (int) $filter['status']]);
        }

        $galleries = $query->count();

        $ids = array_column($query->select('id')->execute()->fetchAll(), 'id');

        $images = Image::query()->whereIn('gallery_id', $ids)->count();

        $teaser = Image::query()->whereIn('gallery_id', $ids)->offset(rand(0, $images-1))->limit(1)->get();
        $teaser = reset($teaser);

        $statuses = Gallery::getStatuses();

        return compact('galleries', 'images', 'statuses', 'teaser');

    }

    /**
     * Rearrange $_FILES array
     * @param $arr
     * @return mixed
     */
    private function rearrange( $arr ){
        foreach( $arr as $key => $all ){
            foreach( $all as $i => $val ){
                $new[$i][$key] = $val;
            }
        }
        return $new;
    }
}
