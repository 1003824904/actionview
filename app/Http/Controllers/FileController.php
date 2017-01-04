<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

use App\Http\Controllers\Controller;
use App\Project\Eloquent\File;
use App\Events\FileUploadEvent;
use App\Events\FileDelEvent;

use DB;

class FileController extends Controller
{
    const SAVE_PATH = '/var/local/docs/'; 

    /**
     * Upload file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String  $project_key
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request, $project_key)
    {
        $fields = array_keys($_FILES); 
        $field = array_pop($fields);
        if ($_FILES[$field]['error'] > 0)
        {
            throw new \UnexpectedValueException('upload file errors.', -10002);
        }

        $basename = md5(microtime() . $_FILES[$field]['name']);
        $sub_save_path = self::SAVE_PATH . substr($basename, 0, 2) . '/';
        if (!is_dir($sub_save_path))
        {
            @mkdir($sub_save_path);
        }
        $filename = '/tmp/' . $basename;
        move_uploaded_file($_FILES[$field]['tmp_name'], $filename);
        $data = [];
        $data['name']    = $_FILES[$field]['name'];
        $data['size']    = $_FILES[$field]['size'];
        $data['type']    = $_FILES[$field]['type'];
        $data['index']   = $basename; 
        if ($_FILES[$field]['type'] == 'image/jpeg' || $_FILES[$field]['type'] == 'image/jpg' || $_FILES[$field]['type'] == 'image/png' || $_FILES[$field]['type'] == 'image/gif')
        {
            $size = getimagesize($filename);
            $width = $size[0]; $height = $size[1];
            $scale = $width < $height ? $height : $width;
            $thumbnails_width = floor(160 * $width / $scale);
            $thumbnails_height = floor(160 * $height / $scale);
            $thumbnails_filename = $filename . '_thumbnails';
            if ($scale <= 160)
            {
                @copy($filename, $thumbnails_filename);
            }
            else if ($_FILES[$field]['type'] == 'image/jpeg' || $_FILES[$field]['type'] == 'image/jpg')
            {
                $src_image = imagecreatefromjpeg($filename);
                $dst_image = imagecreatetruecolor($thumbnails_width, $thumbnails_height);
                imagecopyresized($dst_image, $src_image, 0, 0, 0, 0, $thumbnails_width, $thumbnails_height, $width, $height);
                imagejpeg($dst_image, $thumbnails_filename);
            }
            else if ($_FILES[$field]['type'] == 'image/png')
            {
                $src_image = imagecreatefrompng($filename);
                $dst_image = imagecreatetruecolor($thumbnails_width, $thumbnails_height);
                imagecopyresized($dst_image, $src_image, 0, 0, 0, 0, $thumbnails_width, $thumbnails_height, $width, $height);
                imagepng($dst_image, $thumbnails_filename);
            }
            else if ($_FILES[$field]['type'] == 'image/gif')
            {
                $src_image = imagecreatefromgif($filename);
                $dst_image = imagecreatetruecolor($thumbnails_width, $thumbnails_height);
                imagecopyresized($dst_image, $src_image, 0, 0, 0, 0, $thumbnails_width, $thumbnails_height, $width, $height);
                imagegif($dst_image, $thumbnails_filename);
            }
            else 
            {
                @copy($filename, $thumbnails_filename);
            }
            $data['thumbnails_index'] = $basename . '_thumbnails';
            // move the thumbnails
            @rename($thumbnails_filename, $sub_save_path . $data['thumbnails_index']);
        }
        // move original file
        @rename($filename, $sub_save_path . $basename);
        $data['uploader'] = [ 'id' => $this->user->id, 'name' => $this->user->first_name, 'email' => $this->user->email ];
        $file = File::create($data);

        $issue_id = $request->input('issue_id');
        if (isset($issue_id) && $issue_id)
        {
            Event::fire(new FileUploadEvent($project_key, $issue_id, $field, $file->id, $data['uploader']));
        }

        return Response()->json([ 'ecode' => 0, 'data' => [ 'field' => $field, 'file' => File::find($file->id) ] ]);
    }

    /**
     * Download file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String $id
     */
    public function download(Request $request, $project_key, $id)
    {
        $file = File::find($id); 
        $filepath = self::SAVE_PATH . substr($file->index, 0, 2);
        if ($request->input('flag') == 's')
        {
            $filename = $filepath . '/' . $file->thumbnails_index;
        }
        else 
        {
            $filename = $filepath . '/' . $file->index;
        }

        if (!file_exists($filename))
        {
            throw new \UnexpectedValueException('file does not exist.', -10002);
        }

        header("Content-type: application/octet-stream"); 
        header("Accept-Ranges: bytes"); 
        header("Accept-Length:" . filesize($filename));
        header("Content-Disposition: attachment; filename=" . $file->name);
        echo file_get_contents($filename);
    }

    /**
     * Delete file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  String $project_key
     * @param  String $id
     * @return \Illuminate\Http\Response
     */
    public function delete(Request $request, $project_key, $id)
    {
        $issue_id = $request->input('issue_id');
        $field_key = $request->input('field_key');
        if (isset($issue_id) && $issue_id && isset($field_key) && $field_key)
        {
            $user = [ 'id' => $this->user->id, 'name' => $this->user->first_name, 'email' => $this->user->email ];
            Event::fire(new FileDelEvent($project_key, $issue_id, $field_key, $id, $user));
        }

        $issue = DB::collection('issue_' . $project_key)->where('_id', $issue_id)->first();
        if (array_search($id, $issue[$field_key]) === false)
        {
            return Response()->json(['ecode' => 0, 'data' => ['id' => $id]]);
        }
        else
        {
            throw new \UnexpectedValueException('file deletion failed.', -10002);
        }
    }
}
