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
                                            <div style="width:36px;height:36px;background:#dc2626;color:#ffffff;font-size:20px;line-height:36px;text-align:center;font-weight:600">⚠️</div>
                                        </td>
                                        <td style="padding-left:12px">
                                            <div style="font-size:18px;font-weight:600;color:#18181b;line-height:1.3">账户安全警告</div>
                                            <div style="font-size:14px;color:#71717a;line-height:1.4;padding-top:2px">Account Security Alert</div>
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
                                我们检测到您的账户存在异常活动，可能存在安全风险。为了保护您的账户安全，请立即采取措施。
                                @if(!empty($content ?? ''))
                                <br /><br />
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:16px">
                                    <div style="font-size:14px;line-height:1.6;color:#52525b">
                                        {!! nl2br($content ?? '') !!}
                                    </div>
                                </div>
                                @endif
                            </td>
                        </tr>
                    </table>

                    <!-- 重要提醒 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 16px 24px">
                                <div style="background:#fefce8;border:1px solid #fde047;padding:16px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;padding-bottom:8px">⚠️ 安全提醒</div>
                                    <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:1.8;color:#52525b">
                                        <li>请勿将账户信息分享给他人</li>
                                        <li>请勿将账号密码告知任何人</li>
                                        <li>账户信息泄露可能导致账号被封禁</li>
                                        <li>请妥善保管您的个人账户信息</li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 建议操作 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;padding-bottom:8px">✓ 建议操作</div>
                                    <ol style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:1.8;color:#52525b">
                                        <li>登录您的账户控制面板</li>
                                        <li>重置您的账户访问链接</li>
                                        <li>修改账户密码</li>
                                        <li>如非本人操作，请立即联系客服</li>
                                    </ol>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 按钮 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <a href="{{$url}}" style="display:inline-block;background:#18181b;color:#ffffff;padding:11px 24px;text-decoration:none;font-size:14px;font-weight:500">立即登录账户</a>
                            </td>
                        </tr>
                    </table>

                    <!-- 页脚 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="background:#fafafa;padding:20px 24px;border-top:1px solid #e4e4e7;font-size:13px;line-height:1.6;color:#71717a">
                                此邮件由系统自动发送，请勿直接回复。如有疑问，请联系客服支持。
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
