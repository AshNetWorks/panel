<div style="background:#fafafa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="padding:40px 0">
        <tbody>
        <tr>
            <td>
                <div style="background:#ffffff;border:1px solid #e4e4e7">
                    <!-- 警告标识 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background:#fef2f2;border-bottom:1px solid #fecaca">
                        <tr>
                            <td style="padding:20px 24px">
                                <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td width="40" style="vertical-align:top">
                                            <div style="width:36px;height:36px;background:#dc2626;color:#ffffff;font-size:20px;line-height:36px;text-align:center;font-weight:600">⏰</div>
                                        </td>
                                        <td style="padding-left:12px">
                                            <div style="font-size:18px;font-weight:600;color:#18181b;line-height:1.3">服务到期提醒</div>
                                            <div style="font-size:14px;color:#71717a;line-height:1.4;padding-top:2px">您的服务将在 <strong style="color:#dc2626;font-weight:600">24小时内</strong> 到期</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>

                    <!-- 主要内容 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:24px;font-size:15px;line-height:1.7;color:#27272a">
                                尊敬的用户您好，
                                <br /><br />
                                您在 <strong style="color:#18181b;font-weight:600">{{$name}}</strong> 的服务将在 <strong style="color:#dc2626;font-weight:600">24小时内到期</strong>。为了确保服务不中断，建议您尽快完成续费。如果您已经续费，请忽略此邮件。
                            </td>
                        </tr>
                    </table>

                    <!-- 时间线卡片 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:20px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;text-align:center;padding-bottom:16px">到期时间线</div>

                                    <div style="padding:14px;background:#ffffff;border:1px solid #e4e4e7;border-left:3px solid #22c55e;margin-bottom:12px">
                                        <div style="font-size:12px;color:#71717a;padding-bottom:4px">当前时间</div>
                                        <div style="font-size:14px;font-weight:600;color:#18181b">{{date('Y-m-d H:i:s')}}</div>
                                    </div>

                                    <div style="text-align:center;padding:12px 0">
                                        <span style="display:inline-block;background:#dc2626;color:#ffffff;padding:8px 16px;font-size:13px;font-weight:600">⏱️ 剩余时间：不足 24 小时</span>
                                    </div>

                                    <div style="padding:14px;background:#ffffff;border:1px solid #e4e4e7;border-left:3px solid #dc2626;margin-top:12px">
                                        <div style="font-size:12px;color:#71717a;padding-bottom:4px">预计到期</div>
                                        <div style="font-size:14px;font-weight:600;color:#dc2626">24小时内</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 续费步骤 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 16px 24px">
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;padding-bottom:8px">✓ 续费步骤</div>
                                    <ol style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:1.8;color:#52525b">
                                        <li>登录您的账户控制面板</li>
                                        <li>进入"服务管理"页面</li>
                                        <li>选择需要续费的服务</li>
                                        <li>选择续费周期并完成支付</li>
                                    </ol>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 重要提示 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fefce8;border:1px solid #fde047;padding:14px">
                                    <div style="font-size:13px;line-height:1.6;color:#713f12">
                                        <strong style="font-weight:600">重要提示：</strong>服务到期后将无法继续使用，数据和配置将保留 7 天，超期后将被永久删除。
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 按钮 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <a href="{{$url}}" style="display:inline-block;background:#18181b;color:#ffffff;padding:11px 24px;text-decoration:none;font-size:14px;font-weight:500">立即续费</a>
                            </td>
                        </tr>
                    </table>

                    <!-- 页脚 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="background:#fafafa;padding:20px 24px;border-top:1px solid #e4e4e7;font-size:13px;line-height:1.6;color:#71717a">
                                此邮件由系统自动发送，请勿直接回复。如果您已经续费，请忽略此邮件。
                                <br />
                                <a href="{{$url}}" style="color:#3b82f6;text-decoration:none;font-weight:500">返回 {{$name}}</a>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 版权信息 -->
                <div style="text-align:center;padding:20px 0">
                    <span style="font-size:12px;color:#a1a1aa">© {{date('Y')}} {{$name}}</span>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
