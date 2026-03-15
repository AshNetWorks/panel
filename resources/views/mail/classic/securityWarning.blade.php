<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <td valign="middle" style="padding-left:30px;background-color:#dc3545;color:#fff;padding:20px 40px;font-size: 21px;">
                                <strong>⚠️ 订阅链接安全警告</strong>
                            </td>
                        </tr>
                        </thead>
                        <tbody>
                        <tr style="padding:40px 40px 0 40px;display:table-cell">
                            <td style="font-size:20px;line-height:1.5;color:#dc3545;margin-top:40px;font-weight:bold">
                                安全提示：检测到您的订阅链接可能存在共享或泄露风险
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户您好！
                                <br />
                                <br />
                                {!! nl2br($content) !!}
                            </td>
                        </tr>
                        <tr style="padding:40px;display:table-cell">
                            <td style="padding:20px 40px;">
                                <div style="background-color:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:10px 0;">
                                    <strong style="color:#856404;">重要提醒：</strong>
                                    <ul style="margin:10px 0;padding-left:20px;color:#856404;">
                                        <li>请勿与他人共享您的订阅链接</li>
                                        <li>请勿与他人共享您的账号密码</li>
                                        <li>共享订阅、共享账号属于违规行为</li>
                                        <li>多次违规会导致账号被封禁，且不可解封、不退款</li>
                                        <li>请妥善保管您的账号信息，规范使用</li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:0 40px 20px 40px;">
                                <div style="background-color:#d1ecf1;border-left:4px solid #17a2b8;padding:15px;">
                                    <strong style="color:#0c5460;">下一步操作：</strong>
                                    <ol style="margin:10px 0;padding-left:20px;color:#0c5460;">
                                        <li>登录您的账号</li>
                                        <li>获取新的订阅链接</li>
                                        <li>更新您所有客户端的订阅配置</li>
                                        <li>如非本人操作，请立即修改密码</li>
                                    </ol>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align:center;padding:20px 40px;">
                                <a href="{{$url}}" style="display:inline-block;background-color:#415A94;color:#fff;padding:12px 30px;text-decoration:none;border-radius:4px;font-size:16px;">
                                    立即登录系统
                                </a>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7">
                                此邮件由系统自动发送，请勿直接回复。<br/>
                                如有疑问，请联系客服支持。<br/>
                                <a href="{{$url}}" style="font-size:14px;color:#929292">返回{{$name}}</a>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
