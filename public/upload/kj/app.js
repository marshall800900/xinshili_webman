!function(a) {
    "use strict";
    var b = document
        , c = "querySelectorAll"
        , d = "getElementsByClassName"
        , e = function(a) {
        return b[c](a)
    }
        , f = {
        type: 0,
        shade: !0,
        shadeClose: !0,
        fixed: !0,
        anim: "scale"
    }
        , g = {
        extend: function(a) {
            var b = JSON.parse(JSON.stringify(f));
            for (var c in a)
                b[c] = a[c];
            return b
        },
        timer: {},
        end: {}
    };
    g.touch = function(a, b) {
        a.addEventListener("click", function(a) {
            b.call(this, a)
        }, !1)
    }
    ;
    var h = 0
        , i = ["layui-m-layer"]
        , j = function(a) {
        var b = this;
        b.config = g.extend(a),
            b.view()
    };
    j.prototype.view = function() {
        var a = this
            , c = a.config
            , f = b.createElement("div");
        a.id = f.id = i[0] + h,
            f.setAttribute("class", i[0] + " " + i[0] + (c.type || 0)),
            f.setAttribute("index", h);
        var g = function() {
            var a = "object" == typeof c.title;
            return c.title ? '<h3 style="' + (a ? c.title[1] : "") + '">' + (a ? c.title[0] : c.title) + "</h3>" : ""
        }()
            , j = function() {
            "string" == typeof c.btn && (c.btn = [c.btn]);
            var a, b = (c.btn || []).length;
            return 0 !== b && c.btn ? (a = '<span ' + (1 === b && 'class="single"') + ' yes type="1">' + c.btn[0] + "</span>",
            2 === b && (a = '<span no type="0">' + c.btn[1] + "</span>" + a),
            '<div class="layui-m-layerbtn">' + a + "</div>") : ""
        }();
        if (c.fixed || (c.top = c.hasOwnProperty("top") ? c.top : 100,
            c.style = c.style || "",
            c.style += " top:" + (b.body.scrollTop + c.top) + "px"),
        2 === c.type && (c.content = '<div class="mark"><i></i><i></i><i></i><p>' + (c.content || "") + "</p></div>"),
        c.skin && (c.anim = "up"),
        "msg" === c.skin && (c.shade = !1),
            f.innerHTML = (c.shade ? "<div " + ("string" == typeof c.shade ? 'style="' + c.shade + '"' : "") + ' class="layui-m-layershade"></div>' : "") + '<div class="layui-m-layermain" ' + (c.fixed ? "" : 'style="position:static;"') + '><div class="layui-m-layersection"><div class="layui-m-layerchild ' + (c.skin ? "layui-m-layer-" + c.skin + " " : "") + (c.className ? c.className : "") + " " + (c.anim ? "layui-m-anim-" + c.anim : "") + '" ' + (c.style ? 'style="' + c.style + '"' : "") + ">" + g + '<div class="layui-m-layercont">' + c.content + "</div>" + j + "</div></div></div>",
        !c.type || 2 === c.type) {
            var k = b[d](i[0] + c.type)
                , l = k.length;
            l >= 1 && layer.close(k[0].getAttribute("index"))
        }
        document.body.appendChild(f);
        var m = a.elem = e("#" + a.id)[0];
        c.success && c.success(m),
            a.index = h++,
            a.action(c, m)
    }
        ,
        j.prototype.action = function(a, b) {
            var c = this;
            a.time && (g.timer[c.index] = setTimeout(function() {
                layer.close(c.index)
            }, 1e3 * a.time));
            var e = function() {
                var b = this.getAttribute("type");
                0 == b ? (a.no && a.no(),
                    layer.close(c.index)) : a.yes ? a.yes(c.index) : layer.close(c.index)
            };
            if (a.btn)
                for (var f = b[d]("layui-m-layerbtn")[0].children, h = f.length, i = 0; h > i; i++)
                    g.touch(f[i], e);
            if (a.shade && a.shadeClose) {
                var j = b[d]("layui-m-layershade")[0];
                g.touch(j, function() {
                    layer.close(c.index, a.end)
                })
            }
            a.end && (g.end[c.index] = a.end)
        }
        ,
        a.layer = {
            v: "2.0",
            index: h,
            open: function(a) {
                var b = new j(a || {});
                return b.index
            },
            close: function(a) {
                var c = e("#" + i[0] + a)[0];
                c && (c.innerHTML = "",
                    b.body.removeChild(c),
                    clearTimeout(g.timer[a]),
                    delete g.timer[a],
                "function" == typeof g.end[a] && g.end[a](),
                    delete g.end[a])
            },
            closeAll: function() {
                for (var a = b[d](i[0]), c = 0, e = a.length; e > c; c++)
                    layer.close(0 | a[0].getAttribute("index"))
            }
        }
}(window);
$.extend({
    /*弹出提示层*/
    msg: function(msgText, skin, time) {
        layer.open({
            content: msgText || '服务器繁忙，请稍候再试',
            shade: false,
            anim: false,
            skin: skin ? 'y' : 'n',
            time: time || 2
        })
    },
    /*弹出内容和按钮 param是回调*/
    confirm: function(title, content, btn, onclose, param, unclose, skin) {
        return layer.open({
            title: title || false,
            content: content,
            btn: btn || false,
            skin: skin || false,
            shadeClose: onclose || false,
            yes: function(index) {
                unclose || layer.close(index);
                ('object' == typeof (param) || 'function' == typeof (param)) && param.callback()
            }
        })
    },
    gourl: function(url) {
        document.location.href = url
    },
    /*加载层*/
    loading: function(content, time) {
        return layer.open({
            type: 2,
            content: content || '处理中',
            time: time || 5,
            shadeClose: false,
            shade: 'background-color:rgba(0,0,0,0)'
        })
    },
    /*禁止回车*/
    keyEnter: function() {
        document.onkeydown = function(e) {
            var ev = (typeof event != 'undefined') ? window.event : e;
            if (ev.keyCode == 13)
                return false
        }
    },
    /* 发送验证码 */
    countDown: function(codeBtn) {
        var _time = 59
            , _timeBox = codeBtn.next();
        _timeBox.show(),
            $.confirm(false, '验证码发送成功，请注意查收', '确 定', false, '');
        var interval = window.setInterval(function() {
            if (_time == 0) {
                _timeBox.html(60).hide(),
                    codeBtn.html('重新获取').show(),
                    clearInterval(interval)
            } else {
                _timeBox.html(_time),
                    _time--
            }
        }, 1000);
    },
    chkMobile: function(val) {
        var reg = /^1[3|4|5|6|7|8|9]{1}[0-9]{9}$/;
        return reg.test(val) ? true : ($.msg('手机号码格式错误'),
            !1);
    },
    chkIdCard: function(val) {
        var reg = /(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/;
        return reg.test(val) ? true : ($.msg('身份证号码格式错误'),
            !1);
    }
})
