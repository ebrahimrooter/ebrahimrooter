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
        versionCode = 1
        versionName = "1.0"
    }

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
        getByName("debug") { if (ks != null) signingConfig = signingConfigs.getByName("sideload") }
        getByName("release") {
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
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.8.0")
    implementation("androidx.webkit:webkit:1.12.1")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
