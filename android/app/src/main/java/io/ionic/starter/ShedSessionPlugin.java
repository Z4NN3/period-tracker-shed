package io.ionic.starter;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

@CapacitorPlugin(name = "ShedSession")
public class ShedSessionPlugin extends Plugin {
    private static final String ALIAS = "shed.session.aes.v1";
    private SharedPreferences prefs() {
        return getContext().getSharedPreferences("shed_secure_session", Context.MODE_PRIVATE);
    }
    private SecretKey key() throws Exception {
        KeyStore store = KeyStore.getInstance("AndroidKeyStore");
        store.load(null);
        if (store.containsAlias(ALIAS)) return (SecretKey) store.getKey(ALIAS, null);
        KeyGenerator gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        gen.init(new KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256).build());
        return gen.generateKey();
    }
    @PluginMethod
    public void platformInfo(PluginCall call) {
        JSObject result = new JSObject();
        result.put("sdk", android.os.Build.VERSION.SDK_INT);
        call.resolve(result);
    }
    @PluginMethod
    public void save(PluginCall call) {
        try {
            String value = call.getString("value");
            if (value == null) { call.reject("Missing session"); return; }
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, key());
            String data = Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP) + ":" +
                Base64.encodeToString(cipher.doFinal(value.getBytes(StandardCharsets.UTF_8)), Base64.NO_WRAP);
            if (!prefs().edit().putString("session", data).commit()) throw new Exception("Save failed");
            call.resolve();
        } catch (Exception e) { call.reject("Unable to save secure session", e); }
    }
    @PluginMethod
    public void load(PluginCall call) {
        try {
            JSObject result = new JSObject();
            String data = prefs().getString("session", null);
            if (data != null) {
                String[] parts = data.split(":", 2);
                Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
                cipher.init(Cipher.DECRYPT_MODE, key(), new GCMParameterSpec(128, Base64.decode(parts[0], Base64.NO_WRAP)));
                result.put("value", new String(cipher.doFinal(Base64.decode(parts[1], Base64.NO_WRAP)), StandardCharsets.UTF_8));
            }
            call.resolve(result);
        } catch (Exception e) { call.reject("Unable to read secure session", e); }
    }
    @PluginMethod
    public void clear(PluginCall call) {
        try {
            KeyStore store = KeyStore.getInstance("AndroidKeyStore");
            store.load(null);
            if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS);
            if (!prefs().edit().remove("session").commit()) throw new Exception("Clear failed");
            call.resolve();
        } catch (Exception e) { call.reject("Unable to clear secure session", e); }
    }
}
