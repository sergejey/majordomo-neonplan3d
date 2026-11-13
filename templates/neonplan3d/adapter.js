/**
 * MajorDoMo Adapter for NeonPlan 3D
 *
 * Emulates the Home Assistant frontend object (`hass`) allowing NeonPlan 3D
 * custom elements (<neonplan3d-panel> and <neonplan3d-card>) to run natively
 * inside MajorDoMo without any modifications to the core 3D bundles.
 */

export class MajorDoMoHassAdapter {
  constructor(baseUrl = '') {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.listeners = new Map();
    this.pollInterval = null;
    this.hass = null;
    this.targetElement = null;
  }

  async init(targetElement = null) {
    this.targetElement = targetElement;

    // 1. Fetch initial bootstrap payload from MajorDoMo module
    const bootstrapUrl = `${this.baseUrl}/module/neonplan3d.html?ajax=1&action=bootstrap`;
    const resp = await fetch(bootstrapUrl);
    if (!resp.ok) {
      throw new Error(`Failed to bootstrap MajorDoMo NeonPlan 3D: HTTP ${resp.status}`);
    }
    const data = await resp.json();

    const self = this;

    // 2. Build Home Assistant compatible facade object
    this.hass = {
      language: data.language || 'en',
      user: { is_admin: true, name: 'MajorDoMo' },
      areas: data.areas || {},
      floors: {},
      entities: data.entities || {},
      devices: {},
      states: data.states || {},
      config: data.config || {
        latitude: 55.75,
        longitude: 37.61,
        unit_system: { temperature: '°C', length: 'm' }
      },
      connection: {
        async subscribeMessage(callback, msg) {
          self.listeners.set(callback, msg);
          return async () => self.listeners.delete(callback);
        }
      },
      async callWS(msg) {
        return self.callWS(msg);
      },
      async callService(domain, service, data) {
        return self.callService(domain, service, data);
      },
      async callApi(method, path) {
        return [];
      }
    };

    // If floors are present in building, expose them in floors dict
    if (data.building?.floors) {
      for (const fl of data.building.floors) {
        this.hass.floors[fl.id] = { floor_id: fl.id, name: fl.name, level: fl.elevation || 0 };
      }
    }

    if (this.targetElement) {
      this.targetElement.hass = this.hass;
    }

    // 3. Start background state polling & realtime websocket sync
    this.startStateSync();

    return this.hass;
  }

  async callWS(msg) {
    const wsUrl = `${this.baseUrl}/module/neonplan3d.html?ajax=1&action=ws_bridge`;
    const res = await fetch(wsUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(msg)
    });
    if (!res.ok) {
      throw new Error(`WebSocket bridge error: HTTP ${res.status}`);
    }
    const data = await res.json();

    // Trigger local listeners for building / plan changes
    if (msg.type === 'neonplan3d/building/save') {
      const plan = msg.plan || 'main';
      const rev = data.revision;
      for (const [cb, m] of this.listeners) {
        if (m?.type === 'neonplan3d/building/subscribe' && (m?.plan === plan || m?.plan === '*' || !m?.plan)) {
          cb({ revision: rev, plan });
        }
      }
    } else if (msg.type?.startsWith('neonplan3d/plans/')) {
      // Re-query plan list and notify listeners
      this.callWS({ type: 'neonplan3d/plans/list' }).then(planList => {
        for (const [cb, m] of this.listeners) {
          if (m?.type === 'neonplan3d/plans/subscribe') {
            cb(planList);
          }
        }
      });
    }

    return data;
  }

  async callService(domain, service, data) {
    // Optimistic local state update with NEW object references so Lit detects the change
    const entityId = data?.entity_id;
    const target_ids = Array.isArray(entityId) ? entityId : (entityId ? [entityId] : []);

    for (const eid of target_ids) {
      if (!this.hass.states[eid]) continue;
      const prev = this.hass.states[eid];
      const attrs = { ...prev.attributes };
      let newState = prev.state;

      if (service === 'toggle') {
        if (eid.startsWith('cover.') || prev.state === 'open' || prev.state === 'closed') {
          newState = prev.state === 'closed' ? 'open' : 'closed';
          attrs.current_position = newState === 'open' ? 100 : 0;
        } else {
          newState = prev.state === 'on' ? 'off' : 'on';
        }
      } else if (service === 'turn_on') {
        newState = 'on';
        if (data.brightness_pct !== undefined) attrs.brightness = Math.round((data.brightness_pct / 100) * 255);
        if (data.rgb_color) attrs.rgb_color = data.rgb_color;
      } else if (service === 'turn_off') {
        newState = 'off';
      } else if (service === 'open_cover') {
        newState = 'open'; attrs.current_position = 100;
      } else if (service === 'close_cover') {
        newState = 'closed'; attrs.current_position = 0;
      } else if (service === 'set_cover_position' && data.position !== undefined) {
        attrs.current_position = data.position;
        newState = data.position > 0 ? 'open' : 'closed';
      } else if (service === 'set_temperature' && data.temperature !== undefined) {
        attrs.temperature = data.temperature;
      }

      // Create entirely new object references so Lit's strict-equality check fires
      this.hass = {
        ...this.hass,
        states: { ...this.hass.states, [eid]: { ...prev, state: newState, attributes: attrs } }
      };
    }

    if (target_ids.length > 0) this.notifyElement();

    // Send command to MajorDoMo backend
    const srvUrl = `${this.baseUrl}/module/neonplan3d.html?ajax=1&action=call_service`;
    return fetch(srvUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ domain, service, data })
    });
  }

  startStateSync() {
    if (this.pollInterval) clearInterval(this.pollInterval);

    // Fast state polling every 2.5 seconds
    this.pollInterval = setInterval(async () => {
      try {
        const res = await fetch(`${this.baseUrl}/module/neonplan3d.html?ajax=1&action=states`);
        if (!res.ok) return;
        const json = await res.json();
        if (json.states) {
          let changed = false;
          let newStates = this.hass.states;
          for (const [id, st] of Object.entries(json.states)) {
            const prev = newStates[id];
            if (!prev || prev.state !== st.state || JSON.stringify(prev.attributes) !== JSON.stringify(st.attributes)) {
              if (!changed) newStates = { ...newStates }; // copy-on-first-change
              newStates[id] = st; // server response is always a fresh object
              changed = true;
            }
          }
          if (changed) {
            this.hass = { ...this.hass, states: newStates };
            this.notifyElement();
          }
        }
      } catch (e) {
        // network or server sleep
      }
    }, 2500);

    // If MajorDoMo WebSocket server is running, subscribe to realtime events
    // MajorDoMo uses jQuery pub/sub pattern: $.publish('wsData', response)
    if (typeof $ !== 'undefined' && typeof $.subscribe === 'function') {
      try {
        $.subscribe('wsData', (_, response) => {
          if (response.action === 'global_changed' || response.action === 'property_changed') {
            try {
              const d = typeof response.data === 'string' ? JSON.parse(response.data) : response.data;
              if (d && d.object && d.property !== undefined) {
                this.syncSingleProperty(d.object, d.property, d.value);
              }
            } catch (e) {}
          }
        });
      } catch (err) {}
    } else if (window.wsClient && typeof window.wsClient.subscribe === 'function') {
      // Fallback for non-jQuery WebSocket clients
      try {
        window.wsClient.subscribe((event) => {
          if (event?.type === 'property_changed' && event.object) {
            this.syncSingleProperty(event.object, event.property, event.value);
          }
        });
      } catch (err) {}
    }
  }

  syncSingleProperty(objectName, prop, value) {
    const lowerName = objectName.toLowerCase();
    for (const [id] of Object.entries(this.hass.entities)) {
      // Match the entity directly (sensor_temp, relay, etc.)
      // Also match the virtual entities emitted for temperature/humidity:
      //   sensor.<obj>_temperature  and  sensor.<obj>_humidity
      const directMatch = id.endsWith('.' + lowerName);
      const tempMatch   = id === 'sensor.' + lowerName + '_temperature';
      const humMatch    = id === 'sensor.' + lowerName + '_humidity';

      if (!directMatch && !tempMatch && !humMatch) continue;

      const st = this.hass.states[id];
      if (!st) continue;

      const newAttrs = { ...st.attributes };
      let newState = st.state;

      if (id.startsWith('climate.')) {
        // Thermostat (climate entity)
        // In MajorDoMo: currentTargetValue is target temp, value is room temp
        if (prop === 'currentTargetValue') {
          newAttrs.temperature = parseFloat(value);
        } else if (prop === 'value') {
          newAttrs.current_temperature = parseFloat(value);
        } else if (prop === 'valueHumidity') {
          newAttrs.current_humidity = parseFloat(value);
        } else if (prop === 'status') {
          newState = parseInt(value, 10) ? 'heat' : 'off';
        }
      } else if (id.startsWith('cover.')) {
        // Openable / Cover
        // In MajorDoMo: status = 1 means closed, status = 0 means open
        if (prop === 'status') {
          const isClosed = (parseInt(value, 10) === 1);
          newState = isClosed ? 'closed' : 'open';
          newAttrs.current_position = isClosed ? 0 : 100;
        } else if (prop === 'levelWork' || prop === 'level') {
          const pos = parseInt(value, 10);
          newAttrs.current_position = pos;
          newState = pos > 0 ? 'open' : 'closed';
        }
      } else if (id.startsWith('binary_sensor.') && (st.attributes.device_class === 'door' || st.attributes.device_class === 'window')) {
        // Openclose / contact sensor
        // In MajorDoMo: status = 1 means closed, status = 0 means open
        // In HA binary_sensor: 'off' means closed, 'on' means open
        if (prop === 'status') {
          newState = (parseInt(value, 10) === 1) ? 'off' : 'on';
        }
      } else if (id.startsWith('camera.')) {
        // Camera device
        if (prop === 'snapshotPreviewURL' || prop === 'snapshotURL') {
          let url = String(value);
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith('/')) {
            url = '/' + url;
          }
          newAttrs.entity_picture = url;
        } else if (prop === 'status') {
          newState = parseInt(value, 10) ? 'recording' : 'idle';
        }
      } else if (tempMatch) {
        // sensor temperature sub-entity
        if (prop === 'value') newState = String(value);
      } else if (humMatch) {
        // sensor humidity sub-entity
        if (prop === 'valueHumidity' || prop === 'value') newState = String(value);
      } else {
        // All other sensors and device types
        if (prop === 'status') {
          newState = parseInt(value, 10) ? 'on' : 'off';
        } else if (prop === 'value') {
          newState = String(value);
        } else if (prop === 'valueHumidity' && st.attributes.device_class === 'humidity') {
          newState = String(value);
        } else if (prop === 'level') {
          newAttrs.brightness = Math.round((parseInt(value, 10) / 100) * 255);
        }
      }

      this.hass = {
        ...this.hass,
        states: { ...this.hass.states, [id]: { ...st, state: newState, attributes: newAttrs } }
      };
      this.notifyElement();
    }
  }

  notifyElement() {
    if (this.targetElement) {
      // Assign this.hass directly — it must already be a new object reference
      // (created by spread in callService / startStateSync / syncSingleProperty)
      this.targetElement.hass = this.hass;
    }
  }

  destroy() {
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
      this.pollInterval = null;
    }
    this.listeners.clear();
  }
}
