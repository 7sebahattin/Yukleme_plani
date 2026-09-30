plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace  = "com.asyafresh.nfcuidtani"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.asyafresh.nfcuidtani"
        minSdk        = 21
        targetSdk     = 34
        versionCode   = 1
        versionName   = "0.1-tani"
    }

    buildTypes {
        release { isMinifyEnabled = false }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

// BAĞIMLILIK YOK — bilerek. AndroidX/Material kullanılmıyor; arayüz koddan
// kuruluyor ve android.app.Activity yeterli. Böylece proje ağ kısıtlı
// ortamda da sorunsuz derlenir ve APK ~100 KB kalır.
dependencies { }
