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
                                <div style="width:60px;height:60px;background:#fafafa;border:1px solid #e4e4e7;font-size:32px;line-height:60px;text-align:center">📢</div>
                                <div style="font-size:24px;font-weight:700;color:#18181b;padding-top:16px">系统通知</div>
                                <div style="font-size:14px;color:#71717a;padding-top:4px">来自 {{$name}}</div>
                            </td>
                        </tr>
                    </table>

                    <!-- 主要内容 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px;font-size:15px;line-height:1.7;color:#27272a">
                                尊敬的用户您好，
                            </td>
                        </tr>
                    </table>

                    <!-- 通知内容 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:20px">
                                    <div style="color:#52525b;font-size:15px;line-height:1.8">
                                        {!! nl2br($content) !!}
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px;font-size:14px;color:#71717a;text-align:center">
                                感谢您使用我们的服务
                            </td>
                        </tr>
                    </table>

                    <!-- 快捷操作 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:18px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;text-align:center;padding-bottom:12px">快捷链接</div>
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                        <tr>
                                            <td width="50%" align="center" style="padding-right:6px">
                                                <a href="{{$url}}" style="display:block;background:#ffffff;border:1px solid #e4e4e7;color:#18181b;padding:10px 8px;text-decoration:none;font-size:13px;font-weight:500">🏠 返回首页</a>
                                            </td>
                                            <td width="50%" align="center" style="padding-left:6px">
                                                <a href="{{$url}}" style="display:block;background:#ffffff;border:1px solid #e4e4e7;color:#18181b;padding:10px 8px;text-decoration:none;font-size:13px;font-weight:500">⚙️ 控制面板</a>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 主按钮 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <a href="{{$url}}" style="display:inline-block;background:#18181b;color:#ffffff;padding:11px 24px;text-decoration:none;font-size:14px;font-weight:500">访问 {{$name}}</a>
                            </td>
                        </tr>
                    </table>

                    <!-- 提示信息 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:14px">
                                    <div style="font-size:13px;line-height:1.6;color:#1e3a8a">
                                        <strong style="font-weight:600">温馨提示：</strong>建议将官方邮箱加入白名单，避免错过重要通知。
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 页脚 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="background:#fafafa;padding:20px 24px;border-top:1px solid #e4e4e7;font-size:13px;line-height:1.6;color:#71717a">
                                <div style="font-size:12px;color:#a1a1aa;padding-bottom:4px">发送时间：{{date('Y-m-d H:i:s')}}</div>
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
