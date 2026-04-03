<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get ('/unbindTelegram', 'V1\\User\\UserController@unbindTelegram');
            $router->get ('/resetSecurity', 'V1\\User\\UserController@resetSecurity');
            $router->get ('/info', 'V1\\User\\UserController@info');
            $router->post('/newPeriod', 'V1\\User\\UserController@newPeriod');
            $router->post('/redeemgiftcard', 'V1\\User\\UserController@redeemgiftcard');
            $router->post('/changePassword', 'V1\\User\\UserController@changePassword');
            $router->post('/update', 'V1\\User\\UserController@update');
            $router->get ('/getSubscribe', 'V1\\User\\UserController@getSubscribe');
            $router->get ('/getStat', 'V1\\User\\UserController@getStat');
            $router->get ('/checkLogin', 'V1\\User\\UserController@checkLogin');
            $router->post('/transfer', 'V1\\User\\UserController@transfer');
            $router->post('/getQuickLoginUrl', 'V1\\User\\UserController@getQuickLoginUrl');
            $router->get ('/getActiveSession', 'V1\\User\\UserController@getActiveSession');
            $router->post('/removeActiveSession', 'V1\\User\\UserController@removeActiveSession');
            // Order
            $router->post('/order/save', 'V1\\User\\OrderController@save');
            $router->post('/order/checkout', 'V1\\User\\OrderController@checkout');
            $router->get ('/order/check', 'V1\\User\\OrderController@check');
            $router->get ('/order/detail', 'V1\\User\\OrderController@detail');
            $router->get ('/order/fetch', 'V1\\User\\OrderController@fetch');
            $router->get ('/order/getPaymentMethod', 'V1\\User\\OrderController@getPaymentMethod');
            $router->post('/order/cancel', 'V1\\User\\OrderController@cancel');
            // Plan
            $router->get ('/plan/fetch', 'V1\\User\\PlanController@fetch');
            // Invite
            $router->get ('/invite/save', 'V1\\User\\InviteController@save');
            $router->get ('/invite/fetch', 'V1\\User\\InviteController@fetch');
            $router->get ('/invite/details', 'V1\\User\\InviteController@details');
            // Notice
            $router->get ('/notice/fetch', 'V1\\User\\NoticeController@fetch');
            // Ticket
            $router->post('/ticket/reply', 'V1\\User\\TicketController@reply');
            $router->post('/ticket/close', 'V1\\User\\TicketController@close');
            $router->post('/ticket/save', 'V1\\User\\TicketController@save');
            $router->get ('/ticket/fetch', 'V1\\User\\TicketController@fetch');
            $router->post('/ticket/withdraw', 'V1\\User\\TicketController@withdraw');
            // Server
            $router->get ('/server/fetch', 'V1\\User\\ServerController@fetch');
            // Coupon
            $router->post('/coupon/check', 'V1\\User\\CouponController@check');
            // Telegram
            $router->get ('/telegram/getBotInfo', 'V1\\User\\TelegramController@getBotInfo');
            $router->get ('/telegram/getStatus',  'V1\\User\\TelegramController@getStatus');
            // Comm
            $router->get ('/comm/config', 'V1\\User\\CommController@config');
            $router->Post('/comm/getStripePublicKey', 'V1\\User\\CommController@getStripePublicKey');
            // Knowledge
            $router->get ('/knowledge/fetch', 'V1\\User\\KnowledgeController@fetch');
            $router->get ('/knowledge/getCategory', 'V1\\User\\KnowledgeController@getCategory');
            // Stat
            $router->get ('/stat/getTrafficLog',     'V1\\User\\StatController@getTrafficLog');
            $router->get ('/stat/getNodeTrafficLog', 'V1\\User\\StatController@getNodeTrafficLog');
            $router->get ('/stat/getSubSecurity',    'V1\\User\\StatController@getSubSecurity');
            // Subscribe Log
            $router->get('/subscribeLog/fetch', 'V1\\User\\SubscribeLogController@fetch');
            $router->get('/subscribeLog/statistics', 'V1\\User\\SubscribeLogController@statistics');
            // Subscribe Whitelist
            $router->get ('/subscribeWhitelist/fetch', 'V1\\User\\SubscribeWhitelistController@fetch');
            $router->post('/subscribeWhitelist/save',  'V1\\User\\SubscribeWhitelistController@save');
            $router->post('/subscribeWhitelist/drop',  'V1\\User\\SubscribeWhitelistController@drop');
            // ============ Emby 路由（完整版本）============
            // 普通用户功能
            $router->get ('/emby/fetch', 'V1\\User\\EmbyController@fetch');
            $router->post('/emby/save', 'V1\\User\\EmbyController@save');
            $router->post('/emby/drop', 'V1\\User\\EmbyController@drop');
            $router->post('/emby/resetPassword', 'V1\\User\\EmbyController@resetPassword');
            $router->get ('/emby/getServerStatus', 'V1\\User\\EmbyController@getServerStatus');
            
            // 管理员功能（通过权限检查控制访问）
            $router->get ('/emby/getStatistics', 'V1\\User\\EmbyController@getStatistics');
            $router->get ('/emby/getServers', 'V1\\User\\EmbyController@getServers');
            $router->post('/emby/saveServer', 'V1\\User\\EmbyController@saveServer');
            $router->post('/emby/dropServer', 'V1\\User\\EmbyController@dropServer');
            $router->post('/emby/testServer', 'V1\\User\\EmbyController@testServer');
            $router->get ('/emby/getUsers', 'V1\\User\\EmbyController@getUsers');
            $router->post('/emby/dropUser', 'V1\\User\\EmbyController@dropUser');
            $router->post('/emby/syncUsers', 'V1\\User\\EmbyController@syncUsers');
            $router->get ('/emby/getLogs', 'V1\\User\\EmbyController@getLogs');
            
            // 新增的管理员功能路由
            $router->post('/emby/clearLogs', 'V1\\User\\EmbyController@clearLogs');
            $router->get ('/emby/exportLogs', 'V1\\User\\EmbyController@exportLogs');
            $router->post('/emby/batchCleanup', 'V1\\User\\EmbyController@batchCleanup');
            $router->post('/emby/batchSync', 'V1\\User\\EmbyController@batchSync');
            $router->post('/emby/diagnose', 'V1\\User\\EmbyController@diagnose');
        });
    }
}
