<div style="background:#fafafa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="padding:40px 0">
        <tbody>
        <tr>
            <td>
                <div style="background:#ffffff;border:1px solid #e4e4e7">
                    <!-- 头部 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:32px 24px 24px 24px">
                                <div style="width:60px;height:60px;background:#fafafa;border:1px solid #e4e4e7;font-size:32px;line-height:60px;text-align:center">🔑</div>
                                <div style="font-size:24px;font-weight:700;color:#18181b;padding-top:16px">登录验证</div>
                                <div style="font-size:14px;color:#71717a;padding-top:4px">Login Verification</div>
                            </td>
                        </tr>
                    </table>

                    <!-- 主要内容 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px;font-size:15px;line-height:1.7;color:#27272a">
                                尊敬的用户您好，
                                <br /><br />
                                您正在登录到 <strong style="color:#18181b;font-weight:600">{{$name}}</strong>，请在 <strong style="color:#dc2626;font-weight:600">5 分钟内</strong> 点击下方按钮完成登录验证。
                            </td>
                        </tr>
                    </table>

                    <!-- 信息卡片 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:18px">
                                    <table width="100%" border="0" cellspacing="0" cellpadding="6">
                                        <tr style="border-bottom:1px solid #e4e4e7">
                                            <td style="font-size:13px;color:#71717a;font-weight:500;padding:8px 0">请求时间</td>
                                            <td style="font-size:13px;color:#18181b;font-weight:600;text-align:right;padding:8px 0">{{date('Y-m-d H:i:s')}}</td>
                                        </tr>
                                        <tr style="border-bottom:1px solid #e4e4e7">
                                            <td style="font-size:13px;color:#71717a;font-weight:500;padding:8px 0">有效期</td>
                                            <td style="font-size:13px;color:#18181b;font-weight:600;text-align:right;padding:8px 0">5 分钟</td>
                                        </tr>
                                        <tr>
                                            <td style="font-size:13px;color:#71717a;font-weight:500;padding:8px 0">服务</td>
                                            <td style="font-size:13px;color:#18181b;font-weight:600;text-align:right;padding:8px 0">{{$name}}</td>
                                        </tr>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 登录链接 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:14px">
                                    <div style="font-size:11px;color:#a1a1aa;word-break:break-all;font-family:'Courier New',Courier,monospace;line-height:1.6">{{$link}}</div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 安全提示 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fefce8;border:1px solid #fde047;padding:14px">
                                    <div style="font-size:13px;line-height:1.6;color:#713f12">
                                        <strong style="font-weight:600">安全提示：</strong>如果您未授权此登录请求，请忽略此邮件并立即修改您的密码。
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 按钮 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <a href="{{$link}}" style="display:inline-block;background:#18181b;color:#ffffff;padding:11px 24px;text-decoration:none;font-size:14px;font-weight:500">确认登录</a>
                            </td>
                        </tr>
                    </table>

                    <!-- 页脚 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="background:#fafafa;padding:20px 24px;border-top:1px solid #e4e4e7;font-size:13px;line-height:1.6;color:#71717a">
                                此邮件由系统自动发送，请勿直接回复。
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
