<?php

namespace App\Http\Controllers\V1\Affs;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Plan;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\CommissionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AffController extends Controller
{
    // 获取邀请用户列表
    public function invitedUsers(Request $request)
    {
        try {
            // 获取当前用户
            $currentUser = $request->get('user');
            if (!$currentUser) {
                return response([
                    'message' => '用户未登录或登录已过期'
                ], 401);
            }

            // 如果 $currentUser 是数组，转换为对象
            if (is_array($currentUser)) {
                $userId = $currentUser['id'];
            } else {
                $userId = $currentUser->id;
            }

            // 获取分页参数
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            // 先获取所有套餐
            $plans = Plan::pluck('name', 'id')->toArray();

            // 构建查询
            $query = User::where('invite_user_id', $userId);

            // 添加搜索条件
            if ($search) {
                $query->where('email', 'like', "%{$search}%");
            }

            // 添加排序
            $sortBy = $request->input('sort_by');
            $sortOrder = $request->input('sort_order', 'desc');
            if ($sortBy && in_array($sortBy, ['created_at', 'expired_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // 添加状态过滤
            $status = $request->input('status');
            if ($status) {
                $now = time();
                switch ($status) {
                    case 'normal':
                        $query->where(function($q) use ($now) {
                            $q->whereNull('expired_at')
                              ->orWhere('expired_at', 0)
                              ->orWhere(function($q) use ($now) {
                                  $q->where('expired_at', '>', $now)
                                    ->where('expired_at', '>', $now + 7 * 24 * 60 * 60);
                              });
                        });
                        break;
                        
                    case 'expiring':
                        $query->where('expired_at', '>', $now)
                              ->where('expired_at', '<=', $now + 7 * 24 * 60 * 60);
                        break;
                        
                    case 'expired':
                        $query->where('expired_at', '>', 0)
                              ->where('expired_at', '<=', $now);
                        break;
                }
            }

            // 获取分页数据
            $users = $query->paginate($perPage);

            // 格式化数据
            $data = $users->through(function ($user) use ($plans) {
                // 获取已支付的订单
                $orders = Order::where('user_id', $user->id)
                    ->where('status', 3)
                    ->get();
                
                // 统计订单类型
                $orderStats = [
                    'new_purchase' => 0,
                    'renewal' => 0,
                    'upgrade' => 0
                ];
                
                foreach ($orders as $order) {
                    switch ($order->type) {
                        case 1: $orderStats['new_purchase']++; break;
                        case 2: $orderStats['renewal']++; break;
                        case 3: $orderStats['upgrade']++; break;
                    }
                }

                // 获取该用户产生的总佣金
                $totalCommission = CommissionLog::where('user_id', $user->id)
                    ->sum('get_amount');

                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                    'plan_name' => isset($user->plan_id) ? ($plans[$user->plan_id] ?? '未知套餐') : '未订阅',
                    'expired_at' => $user->expired_at,
                    'status' => $user->expired_at ? ($user->expired_at > time() ? 'active' : 'expired') : 'pending',
                    'order_stats' => $orderStats,
                    'total_commission' => $totalCommission  // 添加总佣金字段
                ];
            });

            return response([
                'data' => [
                    'data' => $data->items(),
                    'total' => $data->total(),
                    'current_page' => $data->currentPage(),
                    'per_page' => $data->perPage()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in invitedUsers:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response([
                'message' => '获取邀请用户列表失败',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fetchInviteCode(Request $request)
    {
        try {
            // 获取当前用户
            $currentUser = $request->get('user');
            if (!$currentUser) {
                return response([
                    'message' => '用户未登录或登录已过期'
                ], 401);
            }

            // 如果 $currentUser 是数组，转换为对象
            $userId = is_array($currentUser) ? $currentUser['id'] : $currentUser->id;

            // 获取所有邀请码
            $inviteCodes = InviteCode::select('code')
                ->where('user_id', $userId)
                ->get()
                ->pluck('code')
                ->toArray();
                
            if (empty($inviteCodes)) {
                return response([
                    'codes' => ['暂无']
                ]);
            }

            return response([
                'codes' => $inviteCodes
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in fetchInviteCode:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response([
                'codes' => ['错误']
            ]);
        }
    }

    /**
     * 获取仪表盘数据
     */
    public function dashboard(Request $request)
    {
        try {
            // 获取当前用户
            $currentUser = $request->get('user');
            if (!$currentUser) {
                return response([
                    'message' => '用户未登录或登录已过期'
                ], 401);
            }

            // 如果 $currentUser 是数组，转换为对象
            $userId = is_array($currentUser) ? $currentUser['id'] : $currentUser->id;

            // 获取用户对象（确保我们有完整的用户数据）
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception('User not found');
            }

            // 获取本月开始和结束时间戳
            $monthStart = Carbon::now()->startOfMonth()->timestamp;
            $monthEnd = Carbon::now()->endOfMonth()->timestamp;

            // 获取总用户数
            $totalUsers = User::where('invite_user_id', $userId)->count();

            // 计算本月新增用户数
            $monthUsers = User::where('invite_user_id', $userId) 
                ->whereBetween('created_at', [$monthStart, $monthEnd]) // created_at 在本月范围内
                ->count();
            // 获取佣金比例
            $commissionRate = config('v2board.invite_commission', 10);
            if ($user->commission_rate) {
                $commissionRate = $user->commission_rate;
            }

            // 获取确认中的佣金
            $pendingCommission = (int)Order::where('invite_user_id', $userId)
                ->where('status', 3) // 待支付或待确认状态
                ->where('commission_status', 0)
                ->sum('commission_balance');

            // 处理分销比例
            if (config('v2board.commission_distribution_enable', 0)) {
                $pendingCommission = (int)($pendingCommission * (config('v2board.commission_distribution_l1') / 100));
            }

            // 获取可提现佣金 (从用户表获取)
            $availableCommission = (int)$user->commission_balance;

            // 获取已确认的佣金总额
            $confirmedCommission = (int)CommissionLog::where('invite_user_id', $userId)
                ->sum('get_amount');

            // 获取本月佣金 (已确认的佣金)
            $monthCommission = (int)CommissionLog::where('invite_user_id', $userId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('get_amount');

            // 获取上个月佣金
            $lastMonthStart = strtotime(date('Y-m-01 00:00:00', strtotime('-1 month')));
            $lastMonthEnd = strtotime(date('Y-m-t 23:59:59', strtotime('-1 month')));
            $lastMonthCommission = CommissionLog::where('invite_user_id', $userId)
                ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
                ->sum('get_amount');

            // 获取佣金状态统计
            $commissionStatus = [
                'pending' => $pendingCommission,
                'confirmed' => $confirmedCommission,
                'available' => $availableCommission
            ];

            // 获取账户余额
            $balance = $user->balance ?? 0;

            // 获取最近6个月的用户增长数据
            $userGrowth = [];
            for ($i = 5; $i >= 0; $i--) {
                $start = Carbon::now()->subMonths($i)->startOfMonth()->timestamp;
                $end = Carbon::now()->subMonths($i)->endOfMonth()->timestamp;
                
                $count = User::where('invite_user_id', $userId)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
                
                $userGrowth[] = [
                    'month' => Carbon::now()->subMonths($i)->format('Y-m'),
                    'count' => $count
                ];
            }

            // 获取套餐分布数据
            $planDistribution = User::where('invite_user_id', $userId)
                ->whereNotNull('plan_id')
                ->selectRaw('plan_id, COUNT(*) as count')
                ->groupBy('plan_id')
                ->get()
                ->map(function ($item) {
                    $plan = Plan::find($item->plan_id);
                    return [
                        'name' => $plan ? $plan->name : '未知套餐',
                        'count' => $item->count
                    ];
                });

            return response([
                'data' => [
                    'total_users' => $totalUsers,
                    'month_users' => $monthUsers,
                    'balance' => $balance,
                    'commission_rate' => $commissionRate,
                    'available_commission' => $availableCommission,
                    'pending_commission' => $pendingCommission,
                    'commission_status' => $commissionStatus,
                    'month_commission' => $monthCommission,
                    'last_month_commission' => $lastMonthCommission,
                    'user_growth' => $userGrowth,
                    'plan_distribution' => $planDistribution
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in dashboard:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId ?? 'null',
                'current_user' => $currentUser
            ]);
            
            return response([
                'message' => '获取仪表盘数据失败',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 添加导出功能
    public function exportInvitedUsers(Request $request)
    {
        try {
            // 获取当前用户
            $currentUser = $request->get('user');
            if (!$currentUser) {
                return response([
                    'message' => '用户未登录或登录已过期'
                ], 401);
            }

            $userId = is_array($currentUser) ? $currentUser['id'] : $currentUser->id;
            
            // 获取分页参数
            $page = $request->input('page', 1);
            $perPage = 1000; // 每次导出1000条
            
            // 先获取所有套餐
            $plans = Plan::pluck('name', 'id')->toArray();
            
            // 构建查询
            $query = User::where('invite_user_id', $userId);
            
            // 计算总页数
            $total = $query->count();
            $totalPages = ceil($total / $perPage);
            
            if ($page > $totalPages) {
                return response([
                    'message' => '导出完成',
                    'data' => [
                        'finished' => true,
                        'total' => $total,
                        'current_page' => $page,
                        'total_pages' => $totalPages
                    ]
                ]);
            }
            
            // 获取当前页数据
            $users = $query->offset(($page - 1) * $perPage)
                          ->limit($perPage)
                          ->get();
            
            // 生成CSV数据
            $csvData = [];
            
            // 如果是第一页，添加表头
            if ($page === 1) {
                $csvData[] = [
                    '邮箱',
                    '套餐',
                    '新购订单',
                    '续费订单',
                    '升级订单',
                    '总佣金',
                    '注册时间',
                    '到期时间',
                    '状态'
                ];
            }
            
            foreach ($users as $user) {
                $orders = Order::where('user_id', $user->id)
                    ->where('status', 3)  // 只统计已支付的订单
                    ->get();
                $orderStats = [
                    'new_purchase' => 0,
                    'renewal' => 0,
                    'upgrade' => 0
                ];
                
                foreach ($orders as $order) {
                    switch ($order->type) {
                        case 1: $orderStats['new_purchase']++; break;
                        case 2: $orderStats['renewal']++; break;
                        case 3: $orderStats['upgrade']++; break;
                    }
                }
                
                $status = $user->expired_at ? 
                    ($user->expired_at > time() ? '正常' : '已过期') : 
                    '未订阅';
                
                // 获取总佣金
                $totalCommission = CommissionLog::where('user_id', $user->id)
                    ->sum('get_amount');
                
                $csvData[] = [
                    $user->email,
                    isset($user->plan_id) ? ($plans[$user->plan_id] ?? '未知套餐') : '未订阅',
                    $orderStats['new_purchase'],
                    $orderStats['renewal'],
                    $orderStats['upgrade'],
                    number_format($totalCommission / 100, 2) . ' 元',  // 转换为元并格式化
                    date('Y-m-d H:i:s', $user->created_at),
                    $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '-',
                    $status
                ];
            }
            
            // 创建 CSV 文件
            $filename = 'invited_users_' . date('YmdHis') . '_part' . $page . '.csv';
            $handle = fopen('php://temp', 'r+');
            
            // 添加 UTF-8 BOM
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            foreach ($csvData as $row) {
                fputcsv($handle, $row);
            }
            
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);
            
            return response([
                'data' => [
                    'finished' => false,
                    'content' => base64_encode($csv),
                    'filename' => $filename,
                    'total' => $total,
                    'current_page' => $page,
                    'total_pages' => $totalPages
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in exportInvitedUsers:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response([
                'message' => '导出失败'
            ], 500);
        }
    }
} 