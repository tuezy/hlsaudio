<?php

namespace App\Http\Controllers\Index;

use App\Http\Requests\FileRequest;
use App\Jobs\ConvertAudioToM3u8;
use App\Models\Audio;
use App\Models\Cms;
use App\Models\Playlist;
use App\Repository\Audio\AudioRepositoryContract;
use App\Repository\Playlists\PlaylistRepositoryContract;
use App\Repository\Videos\VideoRepositoryContract;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Symfony\Component\Process\Process;

class CustomerController extends IndexController
{
    protected $videoRepository;
    protected $audioRepository;

    protected $playlistRepository;
    public function __construct(VideoRepositoryContract $videoRepository, AudioRepositoryContract $audioRepository, PlaylistRepositoryContract $playlistRepository)
    {
        $this->videoRepository = $videoRepository;
        $this->audioRepository = $audioRepository;
        $this->playlistRepository = $playlistRepository;
    }

    public function updateStatusPlaylist(){
        $playlist = $this->playlistRepository
            ->where('status', '=', Playlist::PLAYLIST_STATUS_PROCESSING)
            ->where('customer_id', '=', Auth::guard("customers")->user()->id)
            ->pluck('id');
        if($playlist->count() == 0){
            return response()->json(['success' => true], 200);
        }
        return response()->json(['ids' => array_values($playlist->toArray())], 200);
    }

    public function uploadAudio(Request $request){
        $input = $request->all();


        $rules = [
            'file' => 'required|mimes:mp3',
            'title' => 'required',
            'broadcast_date' => 'required',
            'broadcast_on' => 'required'
        ];
        $messages = [
            'file.required' => 'B???n ch??a nh???p file ??m thanh.',
            'file.mimes' => 'File ??m thanh ph???i ??? ?????nh d???ng mp3',
            'broadcast_date.required' => 'B???n ch??a ch???n ng??y ph??t',
            'broadcast_on.required' => 'B???n ch??a ch???n bu???i ph??t',
            'title.required' => 'B???n ch??a nh???p ti??u ?????',
        ];
        $validation = Validator::make($input, $rules, $messages);

        if ($validation->fails())
        {
            return redirect()->back()->with(['errors' => $validation->errors()]);
        }

        $title = $input['title'];

        $description = $input['description'];

        $broadcast_date = $input['broadcast_date'];

        $broadcast_on = $input['broadcast_on'];

        $file = $request->file('file');


        $directory_save_as = implode('/', [
            "public",
            'users',
            Auth::guard("customers")->user()->id,
            'audios',
            $broadcast_date,
            $broadcast_on
        ]);

        $path = $file->store($directory_save_as);

        if($path){
            $path = Str::replace('public','storage', $path);
//            $directory = Str::replace('public/','storage/', $directory_save_as);
            $playlist = $this->playlistRepository->updateOrCreate([
                'broadcast_date' => $broadcast_date,
                'customer_id' => Auth::guard("customers")->user()->id,
                'broadcast_on' => $broadcast_on
            ],[
                'status' => Playlist::PLAYLIST_STATUS_PENDING,
                'folder' => $directory_save_as
            ]);

            if($playlist){
                $indexMax = Audio::where('playlist_id','=', $playlist->id)->max('index');

                $media = FFMpeg::open(str_replace("storage/", "public/", $path));

                $durationInSeconds = $media->getDurationInSeconds();

                $output = CarbonInterval::second($durationInSeconds)->cascade()->forHumans();


                $this->audioRepository->create([
                    'customer_id' => Auth::guard("customers")->user()->id,
                    'path' => $path,
                    'content' => $output,
                    'title' => $title,
                    'broadcast_date' => $broadcast_date,
                    'broadcast_on' => $broadcast_on,
                    'playlist_id' => $playlist->id,
                    'index' => $indexMax + 1,
                ]);
            }

        }
        return redirect()->back();
    }

    public function uploadVideo(Request $request){
        $input = $request->all();

        $rules = [
            'file' => 'required|mimes:avi,mp4,mpeg,mov',
            'title' => 'required',
            'category_id'  => 'required|min:1'
        ];


        $messages = [
            'file.required' => 'B???n ch??a nh???p file ??m thanh.',
            'file.mimes' => 'File ??m thanh ph???i ??? ?????nh d???ng mp3',
            'title.required' => 'B???n ch??a nh???p ti??u ?????',
            'category_id.min' => 'B???n ch??a ch???n th??? lo???i',
        ];
        $validation = Validator::make($input, $rules, $messages);

        if ($validation->fails())
        {
            return redirect()->back()->with(['errors' => $validation->errors()]);
        }

        $title = $input['title'];

        $category_id = $input['category_id'];

        $description = $input['description'];

        $file = $request->file('file');


        $directory_save_as = implode('/', [
            "public",
            'users',
            Auth::guard("customers")->user()->id,
            'videos',
            $category_id,
        ]);

        $path = $file->store($directory_save_as);

        if($path){
            $path = Str::replace('public','storage', $path);
            $this->videoRepository->create([
                'customer_id' => Auth::guard("customers")->user()->id,
                'path' => $path,
                'content' => $description,
                'category_id' => $category_id,
                'title' => $title
            ]);
        }
        return redirect()->back();
    }

    public function deleteAudio(){
        $id = request()->get('id');
        try {
            $audio  = Audio::where('customer_id', '=', Auth::guard("customers")->user()->id)->findOrFail($id);
            $path = $audio->path;
            $deleted = File::delete(storage_path('app/'.Str::replace('storage', 'public', $path)));
            if($deleted){
                $audio->playlist()->update(['status' => 'pending']);
                $audio->delete();
                return response()->json(['success' => true], 200);
            }

            return response()->json(['success' => false], 200);
        }catch (\Exception $exception){
            return $exception->getMessage();
        }
    }
    public function panel(Request $request){
        $today = date('Y-m-d', time());
        $playlist_status = [];
        foreach (Playlist::PLAYLIST_TYPES as $broadcast_on){
            $playlist = $this->playlistRepository
                ->where('broadcast_date', '=', $today)
                ->where('broadcast_on', '=', $broadcast_on)
                ->where('customer_id', '=', Auth::guard("customers")->user()->id)
                ->first();

            if($playlist){
                $playlist_status[$broadcast_on] = $playlist->status == 'completed' ? 'S???n s??ng': 'Ch??a s???n s??ng';
            }
        }



        return view("index.pages.customers.panel", [
            'user' => Auth::guard("customers")->user(),
            'playlist_status' => $playlist_status,
            'playlists' => Auth::guard("customers")->user()->playlist()->orderBy('broadcast_date', 'asc')->get()
        ]);
    }

    public function changePassword(Request $request){
        if($request->has('password') || $request->has('old_password') || $request->has('password_confirmation')) {
            $rules = [
                'old_password' => 'required|current_password:customers',
                'password' => 'required',
                'password_confirmation' => 'required',
            ];
            $messages = [
                'old_password.required' => 'Ch??a nh???p m???t kh???u c??.',
                'old_password.current_password' => 'M???t kh???u c?? kh??ng ch??nh x??c.'
            ];
        }else{
            $rules = [
                'name' => 'required',
            ];
            $messages = [
                'name.required' => 'B???n ch??a nh???p t??n.'
            ];
        }

        $validation = Validator::make($request->all(), $rules, $messages);

        if ($validation->fails())
        {

            return redirect()->back()->withErrors($validation);
        }

        $user = Auth::guard("customers")->user();
        if($request->get('password') != $request->get('password_confirmation')){
            Session::flash("error", "Nh???p l???i m???t kh???u kh??ng kh???p.");
            return redirect()->back();
        }

        $user->password = Hash::make($request->get('password'));

        $user->setRememberToken(Str::random(60));

        $user->save();
        Session::flash("success", "C???p nh???t passowrd th??nh c??ng.");
        return redirect()->back()->with("success", "C???p nh???t passowrd th??nh c??ng.");
    }

    public function makePlaylist($id){
//        Artisan::call("make:playlist ".$id);
        $playlist = Playlist::findOrFail($id);
        $playlist->status = Playlist::PLAYLIST_STATUS_PROCESSING;
        $playlist->save();

        ConvertAudioToM3u8::dispatch($playlist);

        return redirect()->to(route("customers.panel"));
    }

    public function updatePlaylist(Request $request){
        $input = $request->get('data');
        foreach (json_decode($input['indexs']) as $stt => $id){
            $audio = $this->audioRepository->where('id', '=', $id)->update(['index' => $stt + 1]);
        }
        $audio = Audio::findOrFail($id);
        $audio->playlist()->update(["status" => "pending"]);
        return response()->json(['success' => true], 200);
    }

    public function logout(Request $request){
        Auth::guard("customers")->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
