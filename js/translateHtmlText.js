document.addEventListener('mainJsLoaded', function (){

    $('input[name="checkedActiveTo"]').on('change', function() {
        if($('input[name="checkedActiveTo"]').is(':checked')) {
            $('input[name="ACTIVE_TO"]').removeAttr('disabled');
        } else {
            $('input[name="ACTIVE_TO"]').attr('disabled','disabled');
        }
    });

    var key = '',
        url = 'https://translate.yandex.net/api/v1.5/tr.json/translate',
        html_arr = new Map(),
        license_text_ru = 'Переведено сервисом «Яндекс.Переводчик» <a href="https://translate.yandex.ru/">https://translate.yandex.ru/</a>',
        license_text_en = 'Powered by Yandex.Translate <a href="https://translate.yandex.com/">https://translate.yandex.com/</a>';

    $('#copy_ru-en').on('click', function() {

        if( $('#translate_ru-en').is(':checked') ) {
            BX.showWait();
            var txt = $('input[name=PREVIEW_TEXT_ru]').val();
            var translated = translateText(escapeHTML(txt), 'en');
            var readyText = welcomeBackToHTML(translated);
            BXHtmlEditor.editors.PREVIEW_TEXT_en.SetContent(readyText);
            if (BX.message('SITE_ID') == 's1') $('#yandex_translate_license').html(license_text_ru);
            else $('#yandex_translate_license').html(license_text_en);
            BX.closeWait();
        }
        else BXHtmlEditor.editors.PREVIEW_TEXT_en.SetContent($('input[name=PREVIEW_TEXT_ru]').val());

    });

    $('#copy_ru-en1').on('click', function() {

        if( $('#translate_ru-en1').is(':checked') ) {
            BX.showWait();
            var txt = $('input[name=DETAIL_TEXT_ru]').val();
            var translated = translateText(escapeHTML(txt), 'en');
            var readyText = welcomeBackToHTML(translated);
            BXHtmlEditor.editors.DETAIL_TEXT_en.SetContent(readyText);
            if (BX.message('SITE_ID') == 's1') $('#yandex_translate_license1').html(license_text_ru);
            else $('#yandex_translate_license1').html(license_text_en);
            BX.closeWait();
        }
        else BXHtmlEditor.editors.DETAIL_TEXT_en.SetContent($('input[name=DETAIL_TEXT_ru]').val());

    });

    $('#copy_en-ru').on('click', function() {

        if( $('#translate_en-ru').is(':checked') ) {
            BX.showWait();
            var txt = $('input[name=PREVIEW_TEXT_en]').val();
            var translated = translateText(escapeHTML(txt), 'ru');
            var readyText = welcomeBackToHTML(translated);
            BXHtmlEditor.editors.PREVIEW_TEXT_ru.SetContent(readyText);
            if (BX.message('SITE_ID') == 's1') $('#yandex_translate_license').html(license_text_ru);
            else $('#yandex_translate_license').html(license_text_en);
            BX.closeWait();
        }
        else BXHtmlEditor.editors.PREVIEW_TEXT_ru.SetContent($('input[name=PREVIEW_TEXT_en]').val());

    });

    $('#copy_en-ru1').on('click', function() {

        if( $('#translate_en-ru1').is(':checked') ) {
            BX.showWait();
            var txt = $('input[name=DETAIL_TEXT_en]').val();
            var translated = translateText(escapeHTML(txt), 'ru');
            var readyText = welcomeBackToHTML(translated);
            BXHtmlEditor.editors.DETAIL_TEXT_ru.SetContent(readyText);
            if (BX.message('SITE_ID') == 's1') $('#yandex_translate_license1').html(license_text_ru);
            else $('#yandex_translate_license1').html(license_text_en);
            BX.closeWait();
        }
        else BXHtmlEditor.editors.DETAIL_TEXT_ru.SetContent($('input[name=DETAIL_TEXT_en]').val());

    });

    function makeRequest(text , lang) {

        var request = new XMLHttpRequest(),
        data = 'key='+key+'&text='+text+'&lang='+lang;

        var txtTranslated;

        request.open('POST', url, false);
        request.setRequestHeader("Content-type","application/x-www-form-urlencoded");
        request.send(data);

        if (request.status == 200) {
            var res = request.responseText;
            var translatedText = JSON.parse(res);
            if (translatedText.code == 200) txtTranslated = translatedText.text[0];
            else txtTranslated =  'Translate error';
        }

        return txtTranslated;

    }

    function welcomeBackToHTML(text) {

        text = text.replace(/\^/g, ';');

        for(const [key,value] of html_arr) {

            text = text.replace('~'+key+'~', value);
            
        }

        html_arr.clear();

        return text;

    }

    function Len(N) {

        var count=0; 
        do {N/=10; count++} while (N>=1);
        return count;
    }

    function escapeHTML(text) {

        //text = text.replace(/\\/g, '');
        text = text.replace(/&nbsp;/g, ' ');

        var txt_length = text.length,
        count = 0,
        start_pos = 0,
        pointer = 0;

        while(count < txt_length) {

            if(text[count] === '<') start_pos = count;

            if(text[count] === '>') {
                end_post_to_sub = count + 1;
                var tag = text.substring(start_pos, end_post_to_sub);
                html_arr.set('@@'+pointer , tag);
                text = text.replace(tag, '~@@'+pointer+'~');

                count = count - tag.length + 4 + Len(pointer);
                txt_length = text.length,
                pointer++;
            }

            count++;
            
        }

        txt_length = text.length;
        text = text.replace(/;/g, '\^');

        return text;

    }

    function translateText(text, lang) {

        var strLength = text.length,
        fullTranslatedText = '',
        strAr = [];

        if(strLength > 10000) {

            var strCount = Math.ceil(strLength/10000),
            startChar = 0,
            endChar = 10000,
            counter = 0,
            tempText;

            while(text.charAt(endChar - 1) !== '.') {
                endChar--;
            }

            counter = strCount;

            while(counter > 0) {
                tempText = text.substring(startChar, endChar);
                strAr.push(tempText);
                counter--;
                if (counter === 0 && ((strLength - 1) > endChar) ) {
                    strCount++;
                    counter++;
                }
                if (counter !== 0) {
                    startChar = endChar;
                    endChar += 10000;
                    if (text.charAt(endChar) != '') {
                        while(text.charAt(endChar - 1) !== '.' || (text.charAt(endChar - 1) !== '~' && text.charAt(endChar - 2) !== '@')) {
                            endChar--;
                        }
                    }
                }
            }

            for(var i=0; i < strCount; i++) {
                fullTranslatedText += makeRequest(strAr[i], lang);
            }

            return fullTranslatedText;
        } 
        
        fullTranslatedText = makeRequest(text, lang);
        return fullTranslatedText;

    }


});