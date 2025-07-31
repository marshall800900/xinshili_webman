jQuery.extend({
    alertWindow:function(title,content,bgcolor){
        var title = title;
        var content = content;
        var color1;
        if(bgcolor===undefined){
            color1 = "#FF7C00";
        }else{
            color1 = bgcolor;
        }
        if($("body").find(".alertWindow1").length===0){
            var alertHtml = '<div  class="alertWindow1" style="width: 100%;height: 100%; background:rgba(0,0,0,0.5);position: fixed; left:0px; top: 0px; z-index: 9999;">'+
                '<div  style="width: 400px; height: 200px;background: #FFF;margin: 180px auto;border: 2px solid #CFCFCF; border-bottom: 10px solid '+color1+';">'+
                '<div  style="width: inherit;height: 20px;">'+
                '<div class="alertWindowCloseButton1" style="float: right; width: 10px; height: 20px;margin-right:5px;font-family:\'microsoft yahei\';color:'+color1+';cursor: pointer;">X</div>'+
                '</div>'+
                '<h1 class="alertWindowTitle" style="margin-top:20px;text-align:center;font-family:\'瀹嬩綋\';font-size: 18px;font-weight: normal;color: '+color1+';">'+title+'</h1>'+
                '<div class="alertWindowContent" style="width:360px;px;height: 60px;padding-left:20px;padding-right:20px;text-align:center;font-size: 15px;color: #7F7F7F;">'+content+'</div>'+
                '<p><input class="alertWindowCloseSure1" type="button" value="纭畾" style="width: 100px;height: 50px;background:'+color1+';border:none;position: relative;bottom: 18px;font-size:18px;color:#FFFFFF;-webkit-border-radius: 10px;-moz-border-radius: 10px;border-radius: 10px;cursor: pointer;"></p>'+
                '</div>'+
                '</div>';
            $("body").append(alertHtml);
            var $alertWindow = $(".alertWindow1"); //绐楀彛瀵硅薄
            $(".alertWindowCloseButton1").click(function(){
                $alertWindow.hide();
            });
            $(".alertWindowCloseSure1").click(function(){
                $alertWindow.hide();
            });
        }else{
            $(".alertWindowTitle").text(title);
            $(".alertWindowContent").text(content);
            $(".alertWindow1").show();
        }
    }
});