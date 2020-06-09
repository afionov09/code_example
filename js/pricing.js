'use strict';
(function (document, window, index) {
    var isAdvancedUpload = function () {
        var div = document.createElement('div');
        return (('draggable' in div) || ('ondragstart' in div && 'ondrop' in div)) && 'FormData' in window && 'FileReader' in window;
    }();
    // applying the effect for every form
    var forms = document.querySelectorAll('.box');
    Array.prototype.forEach.call(forms, function (form) {
        var input = form.querySelector('input[type="file"]'),
            label = form.querySelector('label'),
            errorMsg = form.querySelector('.box__error span'),
            restart = form.querySelectorAll('.box__restart'),
            droppedFiles = false,
            showFiles = function (files) {
                label.textContent = files.length > 1 ? (input.getAttribute('data-multiple-caption') || '').replace('{count}', files.length) : files[0].name;
            },
            triggerFormSubmit = function () {
                var event = document.createEvent('HTMLEvents');

                // form.dispatchEvent( event );
            };
        // drag&drop files if the feature is available
        if (isAdvancedUpload) {
            form.classList.add('has-advanced-upload'); // letting the CSS part to know drag&drop is supported by the browser

            ['drag', 'dragstart', 'dragend', 'dragover', 'dragenter', 'dragleave'].forEach(function (event) {
                form.addEventListener(event, function (e) {
                    // preventing the unwanted behaviours
                    e.preventDefault();
                    e.stopPropagation();
                });
            });
            form.addEventListener('drop', handleDrop, false);
            ['dragover', 'dragenter'].forEach(function (event) {
                form.addEventListener(event, function () {
                    form.classList.add('is-dragover');
                });
            });
            ['dragleave', 'dragend', 'drop'].forEach(function (event) {
                form.addEventListener(event, function () {
                    form.classList.remove('is-dragover');
                });
            });
            form.addEventListener('drop', function (e) {
                droppedFiles = e.dataTransfer.files; // the files that were dropped
                showFiles(droppedFiles);
                triggerFormSubmit();

            });
        }

        // restart the form if has a state of error/success
        Array.prototype.forEach.call(restart, function (entry) {
            entry.addEventListener('click', function (e) {
                e.preventDefault();
                form.classList.remove('is-error', 'is-success');
                input.click();
            });
        });

        // Firefox focus bug fix for file input
        input.addEventListener('focus', function () {
            input.classList.add('has-focus');
        });
        input.addEventListener('blur', function () {
            input.classList.remove('has-focus');
        });

    });
}(document, window, 0));

var rABS = true; // true: readAsBinaryString ; false: readAsArrayBuffer
var workbook;
var jsonBook;
var jsonBookOutput;
var countField;
var articleField;
var files, f;
var reader;
var arr;
var arrNA;
var toBasket = [];
var uploadFileSize = BX.message('upload_file_size'); // максимальный размер загружаемого файла в Kb
var uploadFileType = BX.message('upload_file_type'); // массив поддерживаемых MIME-типов для файла
var formContainer = $('#upload_order_form');
var siteId = BX.message('siteId');
var siteDir = BX.message('siteDir');
var globalTableAllowed;
var globalTableNotAllowed;

var input = document.getElementById("file");

function loadFile(e) {
    BX.showWait();
    f = files[0];
    reader = new FileReader();
    workbook = '';
    jsonBook = '';
    countField = '';
    articleField = '';

    reader.onload = function (e) {
        var data = e.target.result;
        if (!rABS) data = new Uint8Array(data);

        workbook = XLSX.read(data, {type: rABS ? 'binary' : 'array'});
        $('#stepper').attr('data-modal-open', 'inline');
        modals.openModals();
        var firsStep = document.getElementById('firsStep');
        firsStep.innerHTML = '';
        generateRadioEl('sheetName', workbook.SheetNames).forEach(function (radioEl) {
            var cb = document.createElement('div');
            cb.classList.add('b-checkbox', 'b-checkbox--radio');
            var label = document.createElement('label');
            label.innerHTML = radioEl.radio;
            label.classList.add('b-checkbox__label');
            cb.appendChild(label);
            label.onclick = function () {
                generateRadioField(radioEl.nameSheet, workbook.Sheets[radioEl.nameSheet])
            };
            firsStep.appendChild(cb);
        });
    };

    if (rABS) { reader.readAsBinaryString(f); } else { reader.readAsArrayBuffer(f); }
}

function ajaxIsDone() {
    $('.b-static').hide();
    $('#upload_order_form').hide();
    $('.lk-company-create-order__main').children('.container').remove();
    var check = window.matchMedia("(max-width: 775px)");
    if( check.matches ) $('.pricing-head-ajaxed').css('display', 'inline-grid');
    else $('.pricing-head-ajaxed').css('display', 'flex');
    prepareFile();
}

function getArrWhiteSpaces( count ) {
    var array = [];
    if (count !== 0) {
        for(var i=0;i <= count; i++) {
            array.push('');
        }
    }
    else array = [''];
    return array;
}

function prepareFile() {
    var tableA_w;
    var tableNA_w;
    var flagA_w = false;
    var flagNA_w = false;
    var statusA = 'Доступно для покупки';
    var statusNA = 'Покупка ограничена';
    var newColumns = ['','№','Артикул', 'Наименование', 'Производитель', 'Цена', 'Ед. измерения', 'Количество', 'Сумма', 'Статус'];
    if(globalTableAllowed) {
        tableA_w = XLSX.utils.table_to_sheet(document.getElementById('parse-it-allowed'));
        tableA_w = XLSX.utils.sheet_to_json(tableA_w, { header: 1 });
        flagA_w = true;
    }
    if(globalTableNotAllowed) {
        tableNA_w = XLSX.utils.table_to_sheet(document.getElementById('parse-it-not-allowed')); 
        tableNA_w = XLSX.utils.sheet_to_json(tableNA_w, { header: 1 });
        flagNA_w = true;
    }   
    var firstRowLength = jsonBookOutput[0].length;
    jsonBookOutput[0] = jsonBookOutput[0].concat(newColumns);
    for (var z=1;z<jsonBookOutput.length; z++) {
        if (flagA_w) {
            for (var i=0; i < tableA_w.length; i++) {
                if (tableA_w[i].indexOf(jsonBookOutput[z][articleField]) !== -1)  {
                    var whiteSpacesCount = firstRowLength - jsonBookOutput[z].length;
                    jsonBookOutput[z] = jsonBookOutput[z].concat(getArrWhiteSpaces(whiteSpacesCount));
                    jsonBookOutput[z] = jsonBookOutput[z].concat(tableA_w[i]);
                    jsonBookOutput[z].push(statusA);
                    continue;
                }
            }
        }
        if (flagNA_w) {
            for (var n=0; n < tableNA_w.length; n++) {
                if (tableNA_w[n].indexOf(jsonBookOutput[z][articleField]) !== -1)  {
                    var whiteSpacesCount = firstRowLength - jsonBookOutput[z].length;
                    jsonBookOutput[z] = jsonBookOutput[z].concat(getArrWhiteSpaces(whiteSpacesCount));
                    jsonBookOutput[z] = jsonBookOutput[z].concat(tableNA_w[n]);
                    jsonBookOutput[z].push(statusNA);
                }
            }
        }
    }
    var bookOutput = XLSX.utils.book_new();
    var arrToHandle = Object.entries(workbook.Sheets);

    for(var g=0; g < arrToHandle.length; g++) {

        if(arrToHandle[g][1]['!ref'] !== undefined) {
            var notEmptySheetName = arrToHandle[g][0];
            XLSX.utils.book_append_sheet(bookOutput, workbook.Sheets[notEmptySheetName], notEmptySheetName);
        }

    }
    workbook = bookOutput;
    var worksheet = XLSX.utils.json_to_sheet(jsonBookOutput, { skipHeader: true });
    XLSX.utils.book_append_sheet(workbook, worksheet, "Enex prices");
}

function saveFile() {
    XLSX.writeFile(workbook, f.name);
}

function handleDrop(e) {
    e.stopPropagation();
    e.preventDefault();
    files = e.dataTransfer.files;
    let filesLength = files.length;
    if (files.length > 0) {
        let flag = true;
        for (let i = 0; i < filesLength; i++) {

            let fileSize = files[i].size;
            let fileType = files[i].type;

            if (!isFileSizeValid(fileSize)) {
                let strError = BX.message('MAX_FILE_SIZE_ERROR').replace(/#FILE_NAME#/g, files[i].name);
                formContainer.find('[data-file-size-invalid]').html(strError).removeClass('hidden');
                flag = false;
            }
            if (!isValidFileType(fileType)) {
                let strError = BX.message('FILE_TYPE_ERROR').replace(/#FILE_NAME#/g, files[i].name);
                formContainer.find('[data-file-size-invalid]').html(strError).removeClass('hidden');
                flag = false;
            }
        }
        if (flag) {
            formContainer.find('[data-file-size-invalid]').html('').addClass('hidden');
            loadFile(e);
        }
    }
}

input.addEventListener('drop', handleDrop, false);

function handleFile(e) {
    files = e.target.files;
    let filesLength = files.length;
    if (filesLength > 0) {

        let flag = true;
        for (let i = 0; i < filesLength; i++) {

            let fileSize = files[i].size;
            let fileType = files[i].type;

            if (!isFileSizeValid(fileSize)) {
                let strError = BX.message('MAX_FILE_SIZE_ERROR').replace(/#FILE_NAME#/g, files[i].name);
                formContainer.find('[data-file-size-invalid]').html(strError).removeClass('hidden');
                flag = false;
            }
            if (!isValidFileType(fileType)) {
                let strError = BX.message('FILE_TYPE_ERROR').replace(/#FILE_NAME#/g, files[i].name);
                formContainer.find('[data-file-size-invalid]').html(strError).removeClass('hidden');
                flag = false;
            }
        }
        if (flag) {
            formContainer.find('[data-file-size-invalid]').html('').addClass('hidden');
            loadFile(e);
        }
        $('input#file').val('');
    }
}

// проверяет допустим ли MIME тип файла
// массив допустимых значений MIME типов передается из шаблона
// local/components/citfact/create.order.from.bid/templates/.default/template.php
function isValidFileType(fileType) {
    let flag = false;

    for (let i in uploadFileType) {
        if (fileType == uploadFileType[i]) {
            flag = true;
        }
    }

    return flag;
}

// проверяет размер файла на валидность
// значение максимального размера файла в Kb передается из шаблона
// local/components/citfact/create.order.from.bid/templates/.default/template.php
function isFileSizeValid(fileSize) {
    if (!uploadFileSize) {
        return true;
    }

    let maxFileSize = +uploadFileSize;
    if (!maxFileSize) {
        return true;
    }

    if (+fileSize <= maxFileSize) {
        return true;
    }

    return false;
}

input.addEventListener('change', handleFile, false);

function generateRadioEl(pref, radioArray) {
    var elementsToInsert = [];
    radioArray.forEach(function (radioName) {
        var radio = `<input type="radio" value="" name="${pref}" id="${radioName}" autocomplete="off" class="b-checkbox__input">
		<span class="b-checkbox__box"></span>
		<span class="bigTextSize b-checkbox__text">${radioName}</span>`
        var nameSheet = radioName;
        elementsToInsert.push({radio: radio, nameSheet: nameSheet});

    });
    BX.closeWait();
    return elementsToInsert
}

function generateRadioField(pref, sheet) {
    jsonBook = XLSX.utils.sheet_to_json(sheet, {header: 1});
    jsonBookOutput = XLSX.utils.sheet_to_json(sheet, {header: 1});
    var secondStep = document.getElementById('secondStep');
    var thirdStep = document.getElementById('thirdStep');
    secondStep.innerHTML = '';
    thirdStep.innerHTML = '';
    generateRadioEl(pref + '_Article', jsonBook[0]).forEach(function (radioEl) {
        var cb = document.createElement('div');
        cb.classList.add('b-checkbox', 'b-checkbox--radio');
        var label = document.createElement('label');
        label.innerHTML = radioEl.radio;
        label.classList.add('b-checkbox__label');
        cb.appendChild(label);
        label.onclick = function () {
            articleField = jsonBook[0].indexOf(radioEl.nameSheet)
        };
        secondStep.appendChild(cb);
    });
    generateRadioEl(pref + '_Count', jsonBook[0]).forEach(function (radioEl) {
        var cb = document.createElement('div');
        cb.classList.add('b-checkbox', 'b-checkbox--radio');
        var label = document.createElement('label');
        label.innerHTML = radioEl.radio;
        label.classList.add('b-checkbox__label');
        cb.appendChild(label);
        label.onclick = function () {
            countField = jsonBook[0].indexOf(radioEl.nameSheet)
        };
        thirdStep.appendChild(cb);
    });
}

function autobuyer() {
    var nameColumn = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z'];
    var goodart = [];
    var returnOrder = [];
    var returnNotFound = [];
    var returnNotAllowed = [];
    var arrNotFound = [];
    var badart = [];
    var column = [];

    jsonBook.forEach(function (order) {
        goodart.push(
            [
                order[articleField],
                order[countField]
            ]);
    });

    $.ajax({
        type: "POST",
        url: "/local/components/citfact/create.order.from.bid/ajax.php?site=" + siteId,
        data: {book: goodart, numColArticle: articleField, numColCount: countField, sessid:BX.bitrix_sessid},
        dataType: 'json',
        async: true,
        beforeSend: function () {
            BX.showWait();
        },
        success: function (data) {
            if (data.errors !== undefined && data.errors.length > 0) {
                $('.result-error').html(data.errors);
                console.error('Error: ' + data.errors);
            }
            else {
                if (data.result.order !== undefined || data.result.notallowed !== undefined) {
                    returnOrder = data.result.order;
                    returnNotFound = data.result.notfound;
                    returnNotAllowed = data.result.notallowed;
                    $.magnificPopup.close();
                    if(returnOrder !== null && Object.keys(returnOrder).length > 0) {
                        arr = Object.values(returnOrder);
                        arr.forEach(function(order){
                            toBasket.push({ITEM_ID: order.ID, QUANTITY: order.COUNT});
                        });
                        arr.unshift([
                            '№',
                            BX.message('thead_article'),
                            BX.message('thead_name'),
                            BX.message('thead_manuf'),
                            BX.message('thead_price'),
                            BX.message('thead_measure'),
                            BX.message('thead_count'),
                            BX.message('thead_sum')
                        ]);
                        var table = arrayToTable(arr, {
                            thead: true,
                            attrs: {class: 'table table-sm', id: 'bids'}
                        });
                        globalTableAllowed = arrayToTable(arr, {
                            thead: true,
                            attrs: {id: 'parse-it-allowed', style: 'display: none;'}
                        });
                        //таблица найденных товаров
                        var divMsg = document.createElement("div");
                        divMsg.classList.add('alert', 'alert-success');
                        divMsg.innerHTML = BX.message('may_to_basket');
                        $('.result-table').css("padding-top", "15px");
                        $('.result-table').html(divMsg);
                        $('.result-table').append(globalTableAllowed);
                        $('.result-table').append(table);
                        var btns = `
                        <div class="pagination" style="padding-bottom: 20px;">
                            <a href="javascript:void(0);" class='btn btn--red' data-action='add_to_basket'>${BX.message('bid_to_basket')}</a>
                        </div>`;
                        $('.result-table').append(btns);
                        App.tables.bidsToBasket();
                    }else {
                        var divMsg = document.createElement("div");
                        divMsg.classList.add('alert', 'alert-success');
                        divMsg.innerHTML = BX.message('may_to_basket') + ' 0';
                        $('.result-table').html(divMsg);
                    }

                    //таблица товаров, с ограниченным доступом к покупке
                    if(returnNotAllowed !== null && Object.keys(returnNotAllowed).length > 0) {
                        arrNA = Object.values(returnNotAllowed);
                        arrNA.unshift([
                            '№',
                            BX.message('thead_article'),
                            BX.message('thead_name'),
                            BX.message('thead_manuf'),
                            BX.message('thead_price'),
                            BX.message('thead_measure'),
                            BX.message('thead_count'),
                            BX.message('thead_sum')
                        ]);
                        var tableNA = arrayToTable(arrNA, {
                            thead: true,
                            attrs: {class: 'table table-sm', id: 'not-allowed'}
                        });
                        globalTableNotAllowed = arrayToTable(arrNA, {
                            thead: true,
                            attrs: {id: 'parse-it-not-allowed', style: 'display: none;'}
                        });
                        var divNotAllowed = document.createElement("div");
                        divNotAllowed.classList.add('alert', 'alert-success');
                        divNotAllowed.innerHTML = BX.message('not_allowed');
                        $('.not-allowed-to-buy').addClass('shown');
                        $('.not-allowed-to-buy').html(divNotAllowed);
                        $('.not-allowed-to-buy').append(globalTableNotAllowed);
                        $('.not-allowed-to-buy').append(tableNA);
                        var btnNA = `
                        <div class="pagination" style="padding-bottom: 20px;">
                            <a href="${siteDir}account/communications/bid-on-buy/?type=purchase_request" class="btn btn--red btn--pricing" rel="nofollow noopener" target="_blank">${BX.message('request_to_buy')}</a>
                        </div>`;
                        $('.not-allowed-to-buy').append(btnNA);
                        App.tables.notAllowedToBasket();
                    }

                    //таблица не найденных товаров
                    if (Object.keys(returnNotAllowed).length > 0 || Object.keys(returnOrder).length > 0) {
                        arrNotFound = Object.values(returnNotFound);

                        if (arrNotFound.length > 1) {
                            jsonBook.forEach(function (order, key) {
                                if(arrNotFound.indexOf(String(order[articleField])) !== -1){
                                    if(key === 0) {
                                        column = BX.message('column');
                                    }
                                    else {
                                        if(nameColumn.length <= articleField) {
                                            column = nameColumn[(articleField/nameColumn.length|0)-1]+''+nameColumn[articleField%nameColumn.length]+''+ (key+1);
                                        }
                                        else
                                            column  = nameColumn[articleField]+''+ (key+1);
                                    }
                                    order.unshift(column);
                                    badart.push(order);
                                }
                            });
                            var divBadMsg = document.createElement("div");
                            divBadMsg.classList.add('alert', 'alert-danger');
                            divBadMsg.innerHTML = BX.message('not_may_to_basket');
                            $('.error-table').addClass('shown');
                            $('.error-table').html(divBadMsg);
                            var tableBad = arrayToTable(badart, {
                                thead: true,
                                fromExcel: true,
                                attrs: {class: 'table  table-sm', id: 'not-bids'}
                            });
                            $('.error-table').append(tableBad);
                            App.tables.notBidsToBasket();
                        
                        }
                    }
                }
            }
            ajaxIsDone();
            BX.closeWait();
        },
        complete: function () {
            //BX.closeWait();
        }
    });
}

//клик по кнопке Создать заказ
$('body').on('click', '[data-action=add_to_basket]', function(){
    if(toBasket.length > 0) {
        //проверим пуста ли корзина
        $.ajax({
            type: "POST",
            url: "/local/components/citfact/create.order.from.bid/ajax.php?site=" + siteId,
            data: {action: 'checkEmptyBasket', sessid:BX.bitrix_sessid},
            dataType: 'json',
            async: false,
            beforeSend: function () {
                BX.showWait();
            },
            success: function (data) {
                if (data.result !== undefined && data.result.emptyBasket === 'Y') { //если корзина пустая
                    $.magnificPopup.open({
                        items: {
                            src: '#confirm_modal',
                            type: 'inline',
                            fixedBgPos: true,
                            showCloseBtn: false,
                            removalDelay: 300,
                            mainClass: "mfp-fade",
                        },
                        callbacks: {
                            open: function () {
                                let self = this;
                                self.content.on('click', '[data-confirm="Y"]', function () {
                                    $.ajax({
                                        type: "POST",
                                        url: window.location.href,
                                        data: {items: toBasket, action: 'add2basketMultiple', isAjaxAction: 'Y'},
                                        dataType: 'json',
                                        async: false,
                                        beforeSend: function () {
                                            BX.showWait();
                                        },
                                        success: function (data) {
                                            if (data !== undefined && data.length > 0) {
                                                window.location.href = siteDir + 'cart/';
                                            } else {

                                            }
                                            //BX.closeWait();
                                        },
                                        complete: function () {
                                            //BX.closeWait();
                                        }
                                    });
                                });
                                this.content.on('click', '[data-confirm="N"]', function () {
                                    self.close();
                                });
                            },
                            close: function () {
                                this.content.off('click');
                            }
                        }
                    });
                }
                else { //если корзина не пустая
                    $.magnificPopup.open({
                        items: {
                            src: '#choose_confirm_modal',
                            type: 'inline',
                            fixedBgPos: true,
                            showCloseBtn: false,
                            removalDelay: 300,
                            mainClass: "mfp-fade"
                        },
                        callbacks: {
                            open: function () {
                                let self = this;
                                self.content.on('click', '[data-confirm="clear"]', function () {
                                    $.ajax({
                                        type: "POST",
                                        url: "/local/components/citfact/create.order.from.bid/ajax.php?site=" + siteId,
                                        data: {items: toBasket, action: 'clearBasket'},
                                        dataType: 'json',
                                        async: true,
                                        beforeSend: function () {
                                            BX.showWait();
                                        },
                                        success: function (data) {
                                            if (data.errors !== undefined && data.errors.length > 0) {
                                                console.error('Error: ' + data.errors);
                                            }
                                            else {
                                                window.location.href = siteDir + 'cart/';
                                            }
                                            //BX.closeWait();
                                        },
                                        complete: function () {
                                            //BX.closeWait();
                                        }
                                    });
                                });
                                self.content.on('click', '[data-confirm="merge"]', function () {
                                    $.ajax({
                                        type: "POST",
                                        url: window.location.href,
                                        data: {items: toBasket, action: 'add2basketMultiple', isAjaxAction: 'Y'},
                                        dataType: 'json',
                                        async: true,
                                        beforeSend: function () {
                                            BX.showWait();
                                        },
                                        success: function (data) {
                                            if (data !== undefined && data.length > 0) {
                                                window.location.href = siteDir + 'cart/';
                                            } else {

                                            }
                                            //BX.closeWait();
                                        },
                                        complete: function () {
                                            //BX.closeWait();
                                        }
                                    });
                                });
                                self.content.on('click', '[data-confirm="N"]', function () {
                                    self.close();
                                });
                            },
                            close: function () {
                                this.content.off('click');
                            }
                        }
                    });
                }
                BX.closeWait();
            },
            complete: function () {
                BX.closeWait();
            }
        });

    }
    return false;
});
