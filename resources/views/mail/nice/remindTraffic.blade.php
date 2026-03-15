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
                                            <div style="width:36px;height:36px;background:#ef4444;color:#ffffff;font-size:20px;line-height:36px;text-align:center;font-weight:600">📊</div>
                                        </td>
                                        <td style="padding-left:12px">
                                            <div style="font-size:18px;font-weight:600;color:#18181b;line-height:1.3">流量使用提醒</div>
                                            <div style="font-size:14px;color:#71717a;line-height:1.4;padding-top:2px">您的流量已使用 <strong style="color:#dc2626">{{$percent ?? 95}}%</strong></div>
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
                                系统检测到您的流量使用量已达到 <strong style="color:#dc2626">{{$percent ?? 95}}%</strong>，即将达到套餐上限。为避免影响您的正常使用，建议您尽快采取措施。
                            </td>
                        </tr>
                    </table>

                    <!-- 进度条卡片 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fafafa;border:1px solid #e4e4e7;padding:20px">
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="padding-bottom:12px">
                                        <tr>
                                            <td style="font-size:13px;color:#71717a;font-weight:500">流量使用</td>
                                            <td style="font-size:16px;font-weight:700;color:#dc2626;text-align:right">{{$percent ?? 95}}%</td>
                                        </tr>
                                    </table>
                                    <div style="background:#e4e4e7;height:10px;width:100%">
                                        <div style="background:#dc2626;height:10px;width:{{$percent ?? 95}}%"></div>
                                    </div>
                                    <div style="font-size:12px;color:#a1a1aa;text-align:center;padding-top:12px">剩余流量不足 {{100 - ($percent ?? 95)}}%</div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 建议措施 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 16px 24px">
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:16px">
                                    <div style="font-size:14px;font-weight:600;color:#18181b;padding-bottom:8px">💡 建议措施</div>
                                    <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:1.8;color:#52525b">
                                        <li>升级套餐 - 选择更大流量的服务套餐</li>
                                        <li>购买流量包 - 临时增加流量额度</li>
                                        <li>合理使用 - 减少高流量消耗的活动</li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 节省小贴士 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="padding:0 24px 24px 24px">
                                <div style="background:#fefce8;border:1px solid #fde047;padding:14px">
                                    <div style="font-size:13px;line-height:1.6;color:#713f12">
                                        <strong style="font-weight:600">节省小贴士：</strong>避免下载大文件或观看高清视频，关闭后台应用自动更新。
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- 按钮组 -->
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="center" style="padding:0 24px 24px 24px">
                                <table border="0" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="padding-right:8px">
                                            <a href="{{$url}}" style="display:inline-block;background:#18181b;color:#ffffff;padding:11px 20px;text-decoration:none;font-size:14px;font-weight:500">查看详情</a>
                                        </td>
                                        <td style="padding-left:8px">
                                            <a href="{{$url}}" style="display:inline-block;background:#ffffff;color:#18181b;padding:10px 20px;text-decoration:none;font-size:14px;font-weight:500;border:1px solid #e4e4e7">升级套餐</a>
                                        </td>
                                    </tr>
                                </table>
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
