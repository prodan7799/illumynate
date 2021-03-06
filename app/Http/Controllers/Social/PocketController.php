<?php

namespace App\Http\Controllers\Social;

use App\Helpers\CacheFile;
use Illuminate\Http\Request;

use App\Models\PocketModel;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Input;
use Duellsy\Pockpack\Pockpack;
use Duellsy\Pockpack\PockpackAuth;
use Duellsy\Pockpack\PockpackQueue;
use App\Models\SocialAccount;
use Laravel\Socialite\Two\User;
use App\Models\ContentTag;

class PocketController extends Controller
{
    const PROVIDER = 'pocket';
    private $social_model, $content_tag_model;

    public function __construct()
    {
        $this->social_model = new SocialAccount();
        $this->content_tag_model = new ContentTag();
    }

    public function index(Request $request)
    {
        $social_account = $this->social_model->getSocialAccount(self::PROVIDER);

        if($social_account)
        {
            $pockpack = new Pockpack(env('POCKET_CONSUMER_KEY'), $social_account->access_token);
            $social_data = $pockpack->retrieve(['detailType' => 'complete'])->list;
            $tags = $this->content_tag_model->getProviderTegs(self::PROVIDER);

            $text_search = $request->input('text-search');
            $new_content = $request->input('new-content');

            if(!empty($text_search))
                $social_data = PocketModel::searchContent($social_data, $tags, $request->input('text-search'));
            else if(!empty($new_content))
            {
                $social_data = PocketModel::getNewContent($social_data, $social_account['last_view']);
                SocialAccount::updateTimeLastView(self::PROVIDER);
            }

            $num_pages = round(count($social_data) / 9, 0, PHP_ROUND_HALF_UP);
            $content = CacheFile::tremSpace(view('parts.social.pocket-content')
                ->withData($social_data)
                ->withTags($tags)
                ->withProvider(self::PROVIDER)
                ->withNumPages($num_pages));

            if(empty($text_search) && empty($new_content))
                CacheFile::saveContent(self::PROVIDER, $content);

            return $content;
        }

        return view('parts.button-auth')->withProvider(self::PROVIDER);
    }

    public function auth()
    {
        $pockpack = new PockpackAuth();
        $request_token = $pockpack->connect(env('POCKET_CONSUMER_KEY'));
        $callback_url = env("POCKET_REDIRECT_URI").'?request_token='.$request_token;
        $redirect_url = 'https://getpocket.com/auth/authorize?request_token='.$request_token.'&redirect_uri='.$callback_url;
        return redirect($redirect_url);
    }

    public function logout()
    {
        $social_account = $this->social_model->getSocialAccount(self::PROVIDER);
        if($social_account)
        {
            $social_account->delete();
            CacheFile::deleteCache(self::PROVIDER);
        }
        return redirect('articles');
    }

    public function callback()
    {
        try
        {
            $request_token = Input::get('request_token');
            $pockpack = new PockpackAuth();
            $response_data = $pockpack->receiveTokenAndUsername(env('POCKET_CONSUMER_KEY'), $request_token);
            $user_social = new User();
            $user_social->token = $response_data['access_token'];
            $user_social->email = $response_data['username'];
            $this->social_model->addOrUpdateSocialAccount($user_social, self::PROVIDER);
        }
        catch(\Exception $e)
        {
        }

        return redirect('articles');
    }

}
