// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import NotificationJson from 'interfaces/notification-json';
import { camelCase, forEach } from 'lodash';
import { computed, observable } from 'mobx';
import NotificationDetails, { newEmptyNotificationDetails } from 'models/notification-details';
import { categoryFromName, categoryGroupKey } from 'notification-maps/category';
import { displayType } from 'notification-maps/type';
import { NotificationIdentity } from 'notifications/notification-identity';
import NotificationReadable from 'notifications/notification-readable';
import core from 'osu-core-singleton';

export default class Notification implements NotificationReadable {
  createdAtJson?: string;
  details: NotificationDetails = newEmptyNotificationDetails();
  @observable isMarkingAsRead = false;
  @observable isRead = false;
  name?: string;
  objectId?: number;
  sourceUserId?: number;

  @computed get canMarkRead() {
    return this.id > 0 && !this.isRead;
  }

  @computed get category() {
    return categoryFromName(this.name ?? '');
  }

  @computed get categoryGroupKey() {
    return categoryGroupKey(this);
  }

  @computed get displayType() {
    return displayType(this);
  }

  get identity(): NotificationIdentity {
    return {
      category: this.category,
      id: this.id,
      objectId: this.objectId,
      objectType: this.objectType,
    };
  }

  @computed get messageGroup() {
    if (this.objectType === 'channel') {
      const replacements = {
        title: this.title,
        username: this.details.username,
      };

      const key = `notifications.item.${this.objectType}.${this.category}.${this.details.type}.${this.name}_group`;

      return osu.trans(key, replacements);
    }

    return this.title;
  }

  @computed get stackId() {
    return `${this.objectType}-${this.objectId}-${this.category}`;
  }

  @computed get title() {
    if (core.currentUser?.user_preferences.beatmapset_title_show_original) {
      return osu.presence(this.details.titleUnicode) ?? this.details.title;
    }

    return this.details.title;
  }

  constructor(readonly id: number, readonly objectType: string) {}

  static fromJson(json: NotificationJson): Notification {
    const obj = new Notification(json.id, json.object_type);
    return obj.updateFromJson(json);
  }

  updateFromJson = (json: NotificationJson) => {
    this.createdAtJson = json.created_at;
    this.isRead = json.is_read;
    this.name = json.name;
    this.objectId = json.object_id;
    this.sourceUserId = json.source_user_id;

    this.details = newEmptyNotificationDetails();

    if (typeof json.details === 'object') {
      forEach(json.details, (value, key) => {
        this.details[camelCase(key)] = value;
      });

      if (json.name === 'comment_new' && json.details.reply_to?.user_id === currentUser.id) {
        this.name = 'comment_reply';
      }
    }

    return this;
  }
}
