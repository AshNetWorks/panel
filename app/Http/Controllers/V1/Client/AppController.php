<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AppController extends Controller
{
    public function getConfig(Request $request)
    {                                                                                         
        return response('', 200);  
    }

    public function getVersion(Request $request)
    {
        if (strpos($request->header('user-agent'), 'tidalab/4.0.0') !== false
            || strpos($request->header('user-agent'), 'tunnelab/4.0.0') !== false
        ) {
            if (strpos($request->header('user-agent'), 'Win64') !== false) {
                return response([
                    'data' => [
                        'version' => config('v2board.windows_version'),
                        'download_url' => config('v2board.windows_download_url')
                    ]
                ]);
            } else {
                return response([
                    'data' => [
                        'version' => config('v2board.macos_version'),
                        'download_url' => config('v2board.macos_download_url')
                    ]
                ]);
            }
            return;
        }
        return response([
            'data' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url')
            ]
        ]);
    }
}
