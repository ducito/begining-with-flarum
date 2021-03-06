import Notification from 'flarum/components/Notification';

/**
 * The `DiscussionRenamedNotification` component displays a notification which
 * indicates that a discussion has had its title changed.
 *
 * ### Props
 *
 * - All of the props for Notification
 */
export default class DiscussionRenamedNotification extends Notification {
  icon() {
    return 'pencil';
  }

  href() {
    const notification = this.props.notification;

    return app.route.discussion(notification.subject(), notification.content().postNumber);
  }

  content() {
    return app.trans('core.discussion_renamed_notification', {user: this.props.notification.sender()});
  }
}
