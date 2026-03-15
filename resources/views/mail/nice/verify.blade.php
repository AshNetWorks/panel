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
                                <div style="width:60px;height:60px;background:#fafafa;border:1px solid #e4e4e7;font-size:32px;line-height:60px;text-align:center">✉️</div>
                                <div style="font-size:24px;font-weight:700;color:#18181b;padding-top:16px">邮箱验证</div>
                                <div style="font-size:14px;color:#71717a;padding-top:4px">Email Verification</div>
                            </td>
                        </tr>
                    </table>

                    <!-- 主要内容 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px;font-size:15px;line-height:1.7;color:#27272a">
                                尊敬的用户您好，
                                <br /><br />
                                感谢您使用 <strong style="color:#18181b;font-weight:600">{{$name}}</strong>。为了完成账户验证，请使用以下验证码：
                            </td>
                        </tr>
                    </table>

                    <!-- 验证码展示 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:32px;text-align:center">
                                    <div style="font-size:12px;color:#a1a1aa;letter-spacing:0.5px;text-transform:uppercase;padding-bottom:16px">验证码</div>
                                    <div style="background:#ffffff;border:2px solid #18181b;padding:16px 24px;display:inline-block">
                                        <div style="font-size:36px;font-weight:700;letter-spacing:8px;color:#18181b;font-family:'Courier New',Courier,monospace">{{$code}}</div>
                                    </div>
                                    <div style="font-size:13px;color:#71717a;padding-top:16px">
                                        有效期：<strong style="color:#dc2626;font-weight:600">5 分钟</strong>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 使用说明 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 16px 24px">
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;padding-bottom:8px">📝 使用说明</div>
                                    <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:1.8;color:#52525b">
                                        <li>请在验证页面输入上述验证码</li>
                                        <li>验证码将在 5 分钟后自动失效</li>
                                        <li>请勿将验证码透露给任何人</li>
                                    </ul>
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
                                        <strong style="font-weight:600">安全提示：</strong>如果您未申请此验证码，请忽略此邮件。
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 按钮 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <a href="{{$url}}" style="display:inline-block;background:#ffffff;color:#18181b;padding:10px 24px;text-decoration:none;font-size:14px;font-weight:500;border:1px solid #e4e4e7">返回首页</a>
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
