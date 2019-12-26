## Платежный плагин UAPAY для CMS Joomla 3.x под VirtueMart 3.x

Тестировался плагин на CMS Joomla 3.9.13 - VirtueMart 3.6.8

### Установка
1. Загрузить папку **uapay** на сервер сайта в папку **[корень_сайта]/plugins/vmpayment/**

### Настройка
1. Получите данные для авторизации от сервиса UAPAY (*clientId, secretKey*).
2. В админ. панели сайта перейти во вкладку _**Components → Virtuemart → Payment methods → (Add Payment Method)**_ 
(_**Компоненты → Virtuemart → Способы оплаты → Создать**_)
3. Заполнить поля:
- Payment Name(Название способа оплаты) - UAPAY, 
- Payment Method(Платежный метод) - выбрать из списка "UAPAY"
- Published(Опубликован) - выбрать "Да"

Нажать "Сохранить"

4. Перейти во вкладку "Конфигурация" и заполняем все необходимые поля и сохранить.