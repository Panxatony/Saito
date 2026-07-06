import App from 'models/app';
import _ from 'underscore';

class Html5Notification {
  /**
   * hides notification after this seconds
   */
  private hideAfter: number = 10;

  public constructor() {
    App.eventBus.reply('app:html5-notification:activate', this.activate, this);
    App.eventBus.on('app:html5-notification:available', this.isEnabled, this);
    App.eventBus.on('html5-notification', this.notification);
  }

  public notification(data: {always: boolean, icon: string, message: string, title: string}) {
    const isAppHidden = !App.eventBus.request('isAppVisible');
    data = _.defaults(data, {
      always: false,
      icon: App.settings.get('notificationIcon'),
    });

    if (data.always || isAppHidden) {
      const notification = new Notification(data.title, {
        body: data.message,
        icon: data.icon,
      });

      // prevents chrome to keep the notification on screen endlessly
      const isChrome = navigator.userAgent.toLowerCase().indexOf('chrome') > -1;
      if (isChrome) {
        setTimeout(() => notification.close(), this.hideAfter * 1000);
      }
    }
  }

  private activate() {
    // Both paths requested permission anyway (a no-op once already granted),
    // and Chrome <30 lacked Notification.permission — so just request it.
    Notification.requestPermission();
  }

  private isEnabled() {
    return 'Notification' in window;
  }
}

const instance  = new Html5Notification();

export default instance;
