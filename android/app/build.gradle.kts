plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "ir.example.bankassistant"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.example.bankassistant"
        minSdk = 26                // floating orb: TYPE_APPLICATION_OVERLAY
        targetSdk = 35
        versionCode = 2
        versionName = "1.1"
    }
    // the voice model is read straight from the APK
    androidResources { noCompress += listOf("onnx") }
    // native libraries compressed in the APK (onnxruntime: 22 MB → ~8 MB); unpacked on install
    packaging { jniLibs { useLegacyPackaging = true } }

    // One fixed key so a new APK installs over the old one (sideloaded app, not Play Store).
    // The key is never in the repository: CI writes it from the ANDROID_KEYSTORE_B64 secret,
    // locally put its path and passwords in the environment (see android/README.md).
    val ks = System.getenv("BANK_KEYSTORE")?.let { file(it) }?.takeIf { it.exists() }
    signingConfigs {
        if (ks != null) {
            create("sideload") {
                storeFile = ks
                storePassword = System.getenv("BANK_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("BANK_KEY_ALIAS") ?: "sideload"
                keyPassword = System.getenv("BANK_KEY_PASSWORD") ?: System.getenv("BANK_KEYSTORE_PASSWORD")
            }
        }
    }
    buildTypes {
        // without the key: the machine's own debug key (fine for trying it, not for updates)
        // the voice engine is ~30 MB per processor type: phones (arm64) only in the release APK,
        // plus x86_64 for the emulator in the debug one
        getByName("debug") {
            if (ks != null) signingConfig = signingConfigs.getByName("sideload")
            ndk { abiFilters += listOf("arm64-v8a", "x86_64") }
        }
        getByName("release") {
            ndk { abiFilters += listOf("arm64-v8a") }
            isMinifyEnabled = false
            signingConfig = if (ks != null) signingConfigs.getByName("sideload") else signingConfigs.getByName("debug")
        }
    }
    buildFeatures { buildConfig = true }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    // sherpa-onnx (offline speech engine, Apache-2.0): tools/voice-model/fetch.sh android puts it in libs/
    implementation(files("libs/sherpa-onnx.aar"))
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.webkit:webkit:1.12.1")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
